<?php
/**
 * Monitorización de muestra por equipo: compara lo HECHO contra un PLAN por celda
 * `equipo × valor` de un `select_one` de muestreo (p. ej. rango de edad), con
 * totales y proyección de cierre por equipo.
 *
 * Gemelo de lib/Stats: toma el scoping por filas (RowScope) y el ocultado de
 * columnas (FieldScope) ya normalizados, de modo que un jefe de equipo (con su
 * RowScope) ve solo lo suyo. El eje de EQUIPO reutiliza `forms.stats_team_field`;
 * las columnas de la matriz son los valores de `forms.sample_field`.
 *
 * «Hecho» se cuenta según el DENOMINADOR (`forms.sample_denominator`):
 *   - 'approved'         → solo envíos aprobados.
 *   - 'approved_pending' → aprobados + pendientes (excluye «en espera» y rechazados).
 *
 * Las celdas SIN objetivo pero CON datos se marcan «fuera de plan» y NO se
 * descartan. Los campos secundarios (etapa 1) aportan solo distribución observada.
 */
class Sample {

    /** Centinela del bucket «sin equipo» / «sin valor» (código vacío en el payload). */
    public const NONE = '__none__';

    /**
     * Estados de revisión que cuentan como «hecho» según el denominador.
     *
     * @return string[]
     */
    public static function denominatorStatuses(string $denominator): array {
        return $denominator === 'approved_pending'
            ? ['approved', 'pending']
            : ['approved'];
    }

    /**
     * @param int         $formId      Formulario.
     * @param array|null  $schemaRaw   Esquema XLSForm normalizado (forms.schema_json) o null.
     * @param array|null  $scope       Regla RowScope ya normalizada (o null = sin restricción).
     * @param array|null  $fieldScope  Regla FieldScope ya normalizada (o null).
     * @param string      $locale      Idioma para resolver etiquetas.
     * @param string|null $teamField   Ruta del campo «equipo» (forms.stats_team_field).
     * @param string      $sampleField Ruta del select_one de muestreo principal.
     * @param string      $denominator 'approved' | 'approved_pending'.
     * @param string[]    $secondary   Rutas de campos secundarios (distribución observada).
     * @param array|null  $extraScope  Restricción de filas ADICIONAL (AND), como en Stats.
     * @param string|null $nowUtc      Marca «ahora» UTC (Y-m-d H:i:s) para la proyección;
     *                                 null = time() real (parámetro para tests deterministas).
     */
    public static function compute(
        int $formId,
        ?array $schemaRaw,
        ?array $scope,
        ?array $fieldScope,
        string $locale,
        ?string $teamField,
        string $sampleField,
        string $denominator = 'approved',
        array $secondary = [],
        ?array $extraScope = null,
        ?string $nowUtc = null
    ): array {
        [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');
        if ($extraScope !== null) {
            [$extraSql, $extraP] = RowScope::sqlCondition($extraScope, 'json_payload');
            $scopeSql = "($scopeSql) AND ($extraSql)";
            $scopeP   = array_merge($scopeP, $extraP);
        }

        // Denominador → filtro por estado de revisión (columna desnormalizada).
        $statuses = self::denominatorStatuses($denominator);
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $statusSql = "review_status IN ($placeholders)";

        // Campo de equipo efectivo: configurado y NO oculto por FieldScope en este alcance.
        $teamField = ($teamField !== null && $teamField !== '' && !FieldScope::isHidden($fieldScope, $teamField))
            ? $teamField : null;
        // El campo de muestreo oculto para este usuario = sin panel (no se agrupa por una
        // columna que no ve). Se devuelve una respuesta vacía coherente.
        $sampleHidden = FieldScope::isHidden($fieldScope, $sampleField);

        $resolved = FormSchema::resolve($schemaRaw, $locale);
        $labelsOn = Settings::labelMode() === 'labels';
        $labels   = $resolved['labels'] ?? [];
        $options  = $resolved['options'] ?? [];

        $sampleOpts = $options[$sampleField] ?? [];   // código => etiqueta (orden del esquema)
        $teamOpts   = $teamField !== null ? ($options[$teamField] ?? []) : [];

        // Campos secundarios: solo los visibles y con opciones (select_one/_multiple).
        $secondary = array_values(array_filter(
            $secondary,
            fn($p) => $p !== null && $p !== '' && $p !== $sampleField && !FieldScope::isHidden($fieldScope, (string) $p)
        ));

        // --- Plan vigente: sample_targets → mapa [equipo][valor] = objetivo. ---
        $targetMap  = [];   // team => [ value => target ]
        $teamTarget = [];   // team => suma de objetivos
        $plannedTeams  = []; // team => true
        $plannedValues = []; // value => true
        foreach (DB::run(
            'SELECT team_value, sample_value, target FROM sample_targets WHERE form_id = ?',
            [$formId]
        )->fetchAll() as $r) {
            $tv = (string) $r['team_value'];
            $sv = (string) $r['sample_value'];
            $tg = (int) $r['target'];
            $targetMap[$tv][$sv] = $tg;
            $teamTarget[$tv] = ($teamTarget[$tv] ?? 0) + $tg;
            $plannedTeams[$tv]  = true;
            $plannedValues[$sv] = true;
        }

        // --- Acumuladores del recuento de lo hecho. ---
        $cells   = [];   // team => [ value => done ]
        $teamDone = [];  // team => total hecho
        $teamFirst = []; // team => primer submitted_at (proyección)
        $valuesSeen = []; // value => true (para el eje de columnas)
        $teamsSeen  = []; // team => true (para las filas)
        $secCounts   = []; // field => [ code => count ]
        $secAnswered = []; // field => nº de envíos con respuesta
        $grandDone   = 0;

        if (!$sampleHidden) {
            foreach (DB::stream(
                "SELECT json_payload, submitted_at, review_status
                 FROM submissions_cache
                 WHERE form_id = ? AND $scopeSql AND $statusSql",
                array_merge([$formId], $scopeP, $statuses)
            ) as $r) {
                $payload = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schemaRaw);

                $tvRaw = $teamField !== null ? ($payload[$teamField] ?? null) : null;
                $svRaw = $payload[$sampleField] ?? null;
                $tKey  = ($tvRaw === null || $tvRaw === '' || is_array($tvRaw)) ? self::NONE : (string) $tvRaw;
                $sKey  = ($svRaw === null || $svRaw === '' || is_array($svRaw)) ? self::NONE : (string) $svRaw;

                $cells[$tKey][$sKey] = ($cells[$tKey][$sKey] ?? 0) + 1;
                $teamDone[$tKey]     = ($teamDone[$tKey] ?? 0) + 1;
                $teamsSeen[$tKey]  = true;
                $valuesSeen[$sKey] = true;
                $grandDone++;

                $subAt = $r['submitted_at'];
                if ($subAt !== null && (!isset($teamFirst[$tKey]) || $subAt < $teamFirst[$tKey])) {
                    $teamFirst[$tKey] = $subAt;
                }

                // Campos secundarios: distribución observada de lo hecho.
                foreach ($secondary as $sf) {
                    $v = $payload[$sf] ?? null;
                    if ($v === null || $v === '' || (is_array($v) && $v === [])) continue;
                    $codes = is_array($v)
                        ? array_map('strval', $v)
                        : preg_split('/\s+/', trim((string) $v), -1, PREG_SPLIT_NO_EMPTY);
                    if (!$codes) continue;
                    foreach (array_unique($codes) as $code) {
                        $secCounts[$sf][$code] = ($secCounts[$sf][$code] ?? 0) + 1;
                    }
                    $secAnswered[$sf] = ($secAnswered[$sf] ?? 0) + 1;
                }
            }
        }

        // --- Eje de VALORES (columnas): opciones del esquema, luego códigos extra
        // presentes en datos o en el plan pero ausentes del esquema (fuera de plan). ---
        $valueAxis = [];
        $seenVal = [];
        foreach (array_keys($sampleOpts) as $code) {
            $code = (string) $code;
            $valueAxis[] = $code; $seenVal[$code] = true;
        }
        foreach (array_merge(array_keys($plannedValues), array_keys($valuesSeen)) as $code) {
            $code = (string) $code;
            if (!isset($seenVal[$code])) { $valueAxis[] = $code; $seenVal[$code] = true; }
        }
        $valueMeta = array_map(fn($code) => [
            'value'       => $code,
            'label'       => $code === self::NONE ? '—' : (($labelsOn && isset($sampleOpts[$code])) ? $sampleOpts[$code] : $code),
            'in_schema'   => isset($sampleOpts[$code]) || $code === self::NONE ? isset($sampleOpts[$code]) : false,
        ], $valueAxis);

        // --- Filas de EQUIPO: unión de equipos planificados y presentes en datos. ---
        $teamKeys = [];
        $seenTeam = [];
        foreach (array_merge(array_keys($plannedTeams), array_keys($teamsSeen)) as $tk) {
            $tk = (string) $tk;
            if (!isset($seenTeam[$tk])) { $teamKeys[] = $tk; $seenTeam[$tk] = true; }
        }

        $now = $nowUtc !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $nowUtc)
            ? strtotime($nowUtc . ' UTC')
            : time();

        $teams = [];
        foreach ($teamKeys as $tKey) {
            $doneTotal   = $teamDone[$tKey] ?? 0;
            $targetTotal = $teamTarget[$tKey] ?? 0;
            $rowCells = [];
            foreach ($valueMeta as $vm) {
                $code = $vm['value'];
                $done = $cells[$tKey][$code] ?? 0;
                $hasTarget = isset($targetMap[$tKey][$code]);
                $target = $hasTarget ? $targetMap[$tKey][$code] : null;
                if ($done === 0 && !$hasTarget) continue; // celda vacía y sin plan: se omite
                $rowCells[] = [
                    'value'       => $code,
                    'label'       => $vm['label'],
                    'done'        => $done,
                    'target'      => $target,
                    'pct'         => ($target !== null && $target > 0) ? round($done * 100 / $target, 4) : null,
                    'out_of_plan' => !$hasTarget,
                ];
            }

            // Proyección: al ritmo medio (hecho ÷ días desde el primer envío del equipo),
            // fecha estimada para alcanzar el objetivo total. Estimación, no promesa.
            $projection = null;
            $first = $teamFirst[$tKey] ?? null;
            if ($targetTotal > 0) {
                $remaining = max(0, $targetTotal - $doneTotal);
                if ($remaining === 0) {
                    $projection = ['first_submission' => $first, 'rate_per_day' => null, 'remaining' => 0, 'eta' => null, 'met' => true];
                } elseif ($first !== null && $doneTotal > 0) {
                    $firstTs = strtotime($first . ' UTC');
                    $elapsedDays = max(1 / 24, ($now - $firstTs) / 86400); // piso: 1 hora
                    $rate = $doneTotal / $elapsedDays; // encuestas/día
                    $etaTs = $rate > 0 ? $now + (int) ceil(($remaining / $rate) * 86400) : null;
                    $projection = [
                        'first_submission' => $first,
                        'rate_per_day'     => round($rate, 4),
                        'remaining'        => $remaining,
                        'eta'              => $etaTs !== null ? gmdate('Y-m-d', $etaTs) : null,
                        'met'              => false,
                    ];
                } else {
                    $projection = ['first_submission' => $first, 'rate_per_day' => 0.0, 'remaining' => $remaining, 'eta' => null, 'met' => false];
                }
            }

            $teams[] = [
                'key'          => $tKey,
                'name'         => $tKey === self::NONE ? '—' : (($labelsOn && isset($teamOpts[$tKey])) ? $teamOpts[$tKey] : $tKey),
                'done'         => $doneTotal,
                'target'       => $targetTotal,
                'pct'          => $targetTotal > 0 ? round($doneTotal * 100 / $targetTotal, 4) : null,
                'out_of_plan'  => !isset($plannedTeams[$tKey]),
                'cells'        => $rowCells,
                'projection'   => $projection,
            ];
        }
        // Orden: primero los planificados por objetivo desc, luego los «fuera de plan» por hecho desc.
        usort($teams, function ($a, $b) {
            if ($a['out_of_plan'] !== $b['out_of_plan']) return $a['out_of_plan'] <=> $b['out_of_plan'];
            return [$b['target'], $b['done']] <=> [$a['target'], $a['done']];
        });

        // --- Campos secundarios: distribución observada (top 20 + otros por campo). ---
        $secondaryOut = [];
        foreach ($secondary as $sf) {
            $counts = $secCounts[$sf] ?? [];
            if (!$counts) continue;
            arsort($counts);
            $optMap   = $options[$sf] ?? [];
            $answered = $secAnswered[$sf] ?? 0;
            $opts = [];
            $rank = 0;
            $others = 0;
            foreach ($counts as $code => $c) {
                if ($rank++ < 20) {
                    $opts[] = [
                        'label' => ($labelsOn && isset($optMap[$code])) ? $optMap[$code] : (string) $code,
                        'count' => $c,
                        'pct'   => $answered > 0 ? round($c * 100 / $answered, 4) : 0,
                    ];
                } else {
                    $others += $c;
                }
            }
            $secondaryOut[] = [
                'field'    => $sf,
                'label'    => $labelsOn ? ($labels[$sf] ?? $sf) : $sf,
                'answered' => $answered,
                'options'  => $opts,
                'others'   => $others,
            ];
        }

        $grandTarget = array_sum($teamTarget);

        return [
            'sample_field'   => [
                'key'   => $sampleField,
                'label' => $labelsOn ? ($labels[$sampleField] ?? $sampleField) : $sampleField,
            ],
            'team_field'     => $teamField !== null ? [
                'key'   => $teamField,
                'label' => $labelsOn ? ($labels[$teamField] ?? $teamField) : $teamField,
            ] : null,
            'denominator'    => in_array($denominator, ['approved', 'approved_pending'], true) ? $denominator : 'approved',
            'values'         => $valueMeta,
            'teams'          => $teams,
            'grand'          => [
                'done'   => $grandDone,
                'target' => $grandTarget,
                'pct'    => $grandTarget > 0 ? round($grandDone * 100 / $grandTarget, 4) : null,
            ],
            'has_plan'       => !empty($plannedTeams),
            'secondary'      => $secondaryOut,
            'label_mode'     => Settings::labelMode(),
            'generated_at'   => gmdate('Y-m-d H:i:s', $now),
        ];
    }
}
