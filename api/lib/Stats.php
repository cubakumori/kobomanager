<?php
/**
 * Cálculo de estadísticas de un formulario sobre `submissions_cache`.
 *
 * Fuente de verdad única, compartida por el endpoint autenticado
 * (`v1/forms/stats.php`) y el público de enlaces compartidos
 * (`v1/public/share_stats.php`). Toma el scoping por filas (`RowScope`) y el
 * ocultado de columnas (`FieldScope`) ya normalizados, de modo que ambos
 * caminos heredan idénticas restricciones; quien llama solo decide el alcance.
 *
 * Devuelve el bloque de métricas SIN la clave `form` (la añade quien llama, con
 * el id/nombre que corresponda). La distribución por estado de revisión
 * (`by_status`) es interna: solo se incluye con `$includeReview = true` (los
 * enlaces públicos la omiten).
 */
class Stats {

    /**
     * @param int        $formId       Formulario.
     * @param array|null $schemaRaw    Esquema XLSForm normalizado (forms.schema_json) o null.
     * @param array|null $scope        Regla RowScope ya normalizada (o null = sin restricción).
     * @param array|null $fieldScope   Regla FieldScope ya normalizada (o null).
     * @param string     $locale       Idioma para resolver etiquetas de preguntas/opciones.
     * @param bool       $includeReview Incluir `by_status` y la mezcla de revisión de
     *                                 `by_team` (interno; público = false).
     * @param string|null $teamField   Ruta del campo «equipo» para el desglose por equipo
     *                                 (forms.stats_team_field). NULL = sin desglose.
     * @param string|null $enumField   Ruta del campo «encuestador» dentro del equipo
     *                                 (forms.stats_enumerator_field). NULL/`_submitted_by`
     *                                 = usar el usuario Kobo que envió.
     * @param string|null $filterStatus Restringe TODAS las métricas (series, tendencia,
     *                                 por pregunta, adjuntos, equipo…) a los envíos cuyo
     *                                 estado de revisión más reciente sea ese valor
     *                                 ('pending'|'approved'|'on_hold'|'rejected'). 'all'
     *                                 o null = sin filtro. El encabezado (`total` y
     *                                 `by_status`) SIEMPRE refleja el conjunto completo,
     *                                 para poder cambiar de filtro. Solo aplica con
     *                                 `$includeReview = true` (los enlaces públicos no
     *                                 distinguen estado → siempre 'all').
     */
    public static function compute(
        int $formId,
        ?array $schemaRaw,
        ?array $scope,
        ?array $fieldScope,
        string $locale,
        bool $includeReview = true,
        ?string $teamField = null,
        ?string $enumField = null,
        ?string $filterStatus = null,
        ?array $teamSel = null,
        ?array $extraScope = null
    ): array {
        [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

        // Restricción de filas ADICIONAL (p. ej. el alcance fijo por equipo de un enlace
        // compartido), combinada en AND con el scope. Se pliega en `$scopeSql` para que la
        // hereden TODAS las consultas (incluido el desglose por equipo): es un alcance fijo,
        // no el filtro interactivo. Se hace a nivel SQL para no depender de la profundidad
        // de RowScope (evita fusionar reglas).
        if ($extraScope !== null) {
            [$extraSql, $extraP] = RowScope::sqlCondition($extraScope, 'json_payload');
            $scopeSql = "($scopeSql) AND ($extraSql)";
            $scopeP   = array_merge($scopeP, $extraP);
        }

        // Filtro por estado de revisión (uno de los cuatro estados, o 'all'). Es
        // INDEPENDIENTE de `$includeReview`: este último solo decide si se EXPONE el
        // desglose `by_status` (interno), no si se filtra el conjunto. Así un enlace
        // público puede acotarse a «solo aprobados» sin revelar el flujo de revisión.
        $filter = in_array($filterStatus, ValidationStatus::STATUSES, true) ? $filterStatus : 'all';
        [$statusSql, $statusP] = ValidationStatus::latestFilterSql($filter === 'all' ? null : $filter);

        // Campo de equipo efectivo: configurado y NO oculto por FieldScope en este alcance
        // (no se puede agrupar/filtrar por una columna que el usuario no ve).
        $teamField = ($teamField !== null && $teamField !== '' && !FieldScope::isHidden($fieldScope, $teamField))
            ? $teamField : null;

        // Filtro INTERACTIVO por equipos (checkboxes del desglose). `$teamSel` = lista de
        // claves seleccionadas; '__none__' = bucket «sin equipo». null = todos. Restringe
        // las series, la tendencia y los agregados, PERO no el desglose por equipo (sus
        // barras se mantienen completas para poder marcar/desmarcar). Misma semántica en
        // SQL (by_day/tendencia) y en PHP (gate del bucle, vía RowScope::matches).
        $teamRule = RowScope::teamRule($teamField, $teamSel);
        [$teamSql, $teamP] = RowScope::sqlCondition($teamRule, 'json_payload');

        // Total COMPLETO en alcance (para la tarjeta «Total» del encabezado; nunca filtrado).
        $total = (int) DB::run(
            "SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ? AND $scopeSql",
            array_merge([$formId], $scopeP)
        )->fetch()['c'];

        // Envíos por día (restringido al filtro de estado, si lo hay).
        $byDay = DB::run(
            "SELECT DATE(submitted_at) AS day, COUNT(*) AS count
             FROM submissions_cache
             WHERE form_id = ? AND submitted_at IS NOT NULL AND $scopeSql AND $statusSql AND $teamSql
             GROUP BY DATE(submitted_at)
             ORDER BY day",
            array_merge([$formId], $scopeP, $statusP, $teamP)
        )->fetchAll();
        $byDay = array_map(fn($r) => ['date' => $r['day'], 'count' => (int) $r['count']], $byDay);

        // Si el tramo (primer→último envío) supera 30 días, el gráfico se agrupa por MES
        // (YYYY-MM) en vez de por día, para que no se vuelva ilegible en periodos largos.
        $periodGranularity = 'day';
        $byMonth = [];
        if ($byDay) {
            $first = strtotime($byDay[0]['date']);
            $last  = strtotime($byDay[count($byDay) - 1]['date']);
            if ($first !== false && $last !== false && ($last - $first) > 30 * 86400) {
                $periodGranularity = 'month';
                $acc = [];
                foreach ($byDay as $d) {
                    $m = substr($d['date'], 0, 7); // YYYY-MM (conserva el orden cronológico)
                    $acc[$m] = ($acc[$m] ?? 0) + $d['count'];
                }
                foreach ($acc as $m => $c) {
                    $byMonth[] = ['date' => $m, 'count' => $c];
                }
            }
        }

        // Total ACUMULADO a lo largo de la serie temporal.
        $run = 0;
        foreach ($byDay as &$d) { $run += $d['count']; $d['cumulative'] = $run; }
        unset($d);
        $run = 0;
        foreach ($byMonth as &$m) { $run += $m['count']; $m['cumulative'] = $run; }
        unset($m);

        // Tendencia reciente: últimos 7/30 días vs el periodo ANTERIOR equivalente.
        $tr = DB::run(
            "SELECT
                SUM(submitted_at >= NOW() - INTERVAL 7 DAY)                                          AS last7,
                SUM(submitted_at <  NOW() - INTERVAL 7 DAY  AND submitted_at >= NOW() - INTERVAL 14 DAY) AS prev7,
                SUM(submitted_at >= NOW() - INTERVAL 30 DAY)                                         AS last30,
                SUM(submitted_at <  NOW() - INTERVAL 30 DAY AND submitted_at >= NOW() - INTERVAL 60 DAY) AS prev30
             FROM submissions_cache
             WHERE form_id = ? AND submitted_at IS NOT NULL AND $scopeSql AND $statusSql AND $teamSql",
            array_merge([$formId], $scopeP, $statusP, $teamP)
        )->fetch();
        $pct = fn(int $cur, int $prev): ?float => $prev > 0 ? round(($cur - $prev) * 100 / $prev, 1) : null;
        $last7 = (int) ($tr['last7'] ?? 0); $prev7 = (int) ($tr['prev7'] ?? 0);
        $last30 = (int) ($tr['last30'] ?? 0); $prev30 = (int) ($tr['prev30'] ?? 0);
        $trend = [
            'last_7' => $last7, 'prev_7' => $prev7, 'pct_7' => $pct($last7, $prev7),
            'last_30' => $last30, 'prev_30' => $prev30, 'pct_30' => $pct($last30, $prev30),
        ];

        // Distribución por estado de revisión (solo si se pide; interna). De la misma
        // consulta sale el mapa uid → última revisión, que reutiliza el desglose por
        // equipo para la mezcla de calidad (evita una segunda consulta).
        $byStatus  = ['pending' => 0, 'approved' => 0, 'on_hold' => 0, 'rejected' => 0];
        $statusMap = []; // submission_uid => estado de la última revisión
        if ($includeReview) {
            $reviewed = DB::run(
                "SELECT r.submission_uid, r.status
                 FROM submission_reviews r
                 JOIN (
                    SELECT submission_uid, MAX(id) AS max_id FROM submission_reviews GROUP BY submission_uid
                 ) latest ON latest.max_id = r.id
                 JOIN submissions_cache sc ON sc.submission_uid = r.submission_uid AND sc.form_id = ?
                    AND $scopeSql",
                array_merge([$formId], $scopeP)
            )->fetchAll();

            $reviewedTotal = 0;
            foreach ($reviewed as $r) {
                if (isset($byStatus[$r['status']])) {
                    $byStatus[$r['status']]++;
                    $statusMap[$r['submission_uid']] = $r['status'];
                    $reviewedTotal++;
                }
            }
            // Los envíos sin revisión cuentan como 'pending'.
            $byStatus['pending'] += $total - $reviewedTotal;
        }

        // -----------------------------------------------------------------------
        // Métricas enriquecidas: una sola pasada en PHP sobre los payloads en alcance.
        // -----------------------------------------------------------------------
        $resolved  = FormSchema::resolve($schemaRaw, $locale);
        $labelsOn  = Settings::labelMode() === 'labels';
        $labels    = $resolved['labels'] ?? [];
        $options   = $resolved['options'] ?? [];

        // --- Config del desglose por equipo (opcional). ---
        // `$teamField` ya está resuelto arriba (configurado y visible). El encuestador usa
        // `_submitted_by` salvo que se configure un campo de datos visible.
        $enumIsField = $enumField !== null && $enumField !== '' && $enumField !== '_submitted_by'
            && !FieldScope::isHidden($fieldScope, $enumField);
        $enumPath   = $enumIsField ? $enumField : '_submitted_by';
        $teamOptMap = $teamField !== null ? ($options[$teamField] ?? []) : [];
        $enumOptMap = $enumIsField ? ($options[$enumPath] ?? []) : [];

        // Preguntas de opción (select_one y select_multiple).
        $singleFields = [];
        foreach (($schemaRaw['fields'] ?? []) as $path => $fd) {
            if (FieldScope::isHidden($fieldScope, (string) $path)) continue; // oculta → no se cuenta
            $type  = (string) ($fd['type'] ?? '');
            $multi = str_starts_with($type, 'select_multiple') || !empty($fd['multi']);
            if (str_starts_with($type, 'select_one') || str_starts_with($type, 'select_multiple')) {
                $singleFields[$path] = ['leaf' => $fd['leaf'] ?? $path, 'multi' => $multi];
            }
        }

        // Acumuladores.
        $qCounts    = [];                 // path => [ code => count ]
        $qAnswered  = [];                 // path => nº de envíos con respuesta
        $enumCounts = [];                 // _submitted_by => count
        $durations  = [];                 // duraciones (s) no nulas
        $byHour     = array_fill(0, 24, 0);
        $byDow      = array_fill(0, 7, 0);
        $attWith    = 0;
        $attByKind  = ['image' => 0, 'audio' => 0, 'video' => 0, 'file' => 0];
        $geoWith    = 0;
        $lastSub    = null;

        // Desglose por equipo → encuestador. Acumula una «hoja» de métricas por equipo
        // y por (equipo, encuestador); el nivel de equipo se agrega a la vez que la hoja
        // del encuestador para no recombinar luego.
        $teamAcc = []; // teamKey => ['leaf' => hoja, 'enums' => [enumKey => hoja]]
        $newLeaf = fn(): array => [
            'count' => 0, 'dur' => [], 'compSum' => 0.0, 'compN' => 0, 'last' => null,
            'status' => ['pending' => 0, 'approved' => 0, 'on_hold' => 0, 'rejected' => 0],
        ];
        $bump = function (array &$leaf, array $dd, ?string $subAt, string $st): void {
            $leaf['count']++;
            if ($dd['duration_s'] !== null)   $leaf['dur'][] = $dd['duration_s'];
            if ($dd['completeness'] !== null) { $leaf['compSum'] += $dd['completeness']; $leaf['compN']++; }
            if ($subAt !== null && ($leaf['last'] === null || $subAt > $leaf['last'])) $leaf['last'] = $subAt;
            $leaf['status'][$st]++;
        };

        $rows = DB::run(
            "SELECT submission_uid, json_payload, submitted_at FROM submissions_cache WHERE form_id = ? AND $scopeSql AND $statusSql",
            array_merge([$formId], $scopeP, $statusP)
        )->fetchAll();

        // Denominador del DESGLOSE POR EQUIPO: todos los envíos en alcance + estado (NO el
        // filtro por equipos), para que la cuota de cada equipo sea estable al marcar/desmarcar.
        $teamBase = count($rows);
        // Denominador de las DEMÁS métricas: el subconjunto que además pasa el filtro por
        // equipos. Coincide con `$teamBase` cuando no hay equipos desmarcados.
        $base = 0;

        foreach ($rows as $r) {
            // Recorta los campos ocultos: adjuntos/geo de un campo oculto no cuentan.
            $payload = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schemaRaw);

            // Métricas derivadas (duración, adjuntos, geo, hora/día). Necesarias tanto para el
            // desglose por equipo (siempre) como para los agregados (solo si pasa el filtro).
            $dd = Derived::compute($payload, $schemaRaw, $r['submitted_at']);

            // Desglose por equipo → encuestador: SIEMPRE (las barras se mantienen completas,
            // independientemente de qué equipos estén marcados).
            if ($teamField !== null) {
                $tv = $payload[$teamField] ?? null;
                $ev = $payload[$enumPath] ?? null;
                $tKey = ($tv === null || $tv === '' || is_array($tv)) ? '—' : (string) $tv;
                $eKey = ($ev === null || $ev === '' || is_array($ev)) ? '—' : (string) $ev;
                $st = $statusMap[$r['submission_uid']] ?? 'pending'; // sin revisión → pendiente
                if (!isset($teamAcc[$tKey])) $teamAcc[$tKey] = ['leaf' => $newLeaf(), 'enums' => []];
                if (!isset($teamAcc[$tKey]['enums'][$eKey])) $teamAcc[$tKey]['enums'][$eKey] = $newLeaf();
                $bump($teamAcc[$tKey]['leaf'], $dd, $r['submitted_at'], $st);
                $bump($teamAcc[$tKey]['enums'][$eKey], $dd, $r['submitted_at'], $st);
            }

            // Filtro por equipos: si el envío no entra en la selección, no suma al resto de
            // métricas (mismo criterio que el SQL de by_day/tendencia, vía RowScope::matches).
            if ($teamRule !== null && !RowScope::matches($teamRule, $payload)) continue;
            $base++;

            if ($r['submitted_at'] !== null && ($lastSub === null || $r['submitted_at'] > $lastSub)) {
                $lastSub = $r['submitted_at'];
            }

            // Distribución por pregunta (select_one y select_multiple).
            foreach ($singleFields as $path => $f) {
                $v = $payload[$path] ?? null;
                if ($v === null || $v === '' || is_array($v)) continue;
                if ($f['multi']) {
                    $codes = preg_split('/\s+/', trim((string) $v), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if (!$codes) continue;
                    foreach (array_unique($codes) as $code) {
                        $qCounts[$path][$code] = ($qCounts[$path][$code] ?? 0) + 1;
                    }
                    $qAnswered[$path] = ($qAnswered[$path] ?? 0) + 1;
                } else {
                    $code = (string) $v;
                    $qCounts[$path][$code] = ($qCounts[$path][$code] ?? 0) + 1;
                    $qAnswered[$path] = ($qAnswered[$path] ?? 0) + 1;
                }
            }

            // Por enumerador.
            $by = $payload['_submitted_by'] ?? null;
            $by = ($by === null || $by === '') ? '—' : (string) $by;
            $enumCounts[$by] = ($enumCounts[$by] ?? 0) + 1;

            if ($dd['duration_s'] !== null) $durations[] = $dd['duration_s'];
            if ($dd['submitted_hour'] !== null) $byHour[$dd['submitted_hour']]++;
            if ($dd['submitted_dow'] !== null) $byDow[$dd['submitted_dow']]++;
            if ($dd['has_attachments']) $attWith++;
            foreach ($attByKind as $k => $_) $attByKind[$k] += $dd['attachments_by_kind'][$k] ?? 0;
            if ($dd['has_geo']) $geoWith++;
        }

        // --- Ensamblar «por pregunta»: etiquetas resueltas, ordenado desc, top 20 + otros. ---
        $OPT_CAP = 20;
        $byQuestion = [];
        foreach ($singleFields as $path => $f) {
            $counts = $qCounts[$path] ?? [];
            if (!$counts) continue;
            arsort($counts);
            $optMap   = $options[$path] ?? ($options[$f['leaf']] ?? []);
            $answered = $qAnswered[$path] ?? 0;

            $opts = [];
            $rank = 0;
            $othersCount = 0;
            foreach ($counts as $code => $c) {
                if ($rank++ < $OPT_CAP) {
                    $label = ($labelsOn && isset($optMap[$code])) ? $optMap[$code] : (string) $code;
                    $opts[] = [
                        'label' => $label,
                        'count' => $c,
                        'pct'   => $answered > 0 ? round($c * 100 / $answered, 1) : 0,
                    ];
                } else {
                    $othersCount += $c;
                }
            }

            $qLabel = $labelsOn ? ($labels[$path] ?? ($labels[$f['leaf']] ?? $path)) : $path;
            $byQuestion[] = [
                'field'    => $path,
                'label'    => $qLabel,
                'answered' => $answered,
                'multi'    => $f['multi'],
                'options'  => $opts,
                'others'   => $othersCount,
            ];
        }

        // --- Por enumerador: ordenado desc, top 20 + otros. ---
        arsort($enumCounts);
        $byEnumerator = [];
        $enumOthers = 0;
        $rank = 0;
        foreach ($enumCounts as $name => $c) {
            if ($rank++ < 20) {
                $byEnumerator[] = ['name' => $name, 'count' => $c, 'pct' => $base > 0 ? round($c * 100 / $base, 1) : 0];
            } else {
                $enumOthers += $c;
            }
        }

        // --- Duración: media, mediana e histograma. ---
        $duration = null;
        if ($durations) {
            sort($durations);
            $n = count($durations);
            $median = $n % 2 ? $durations[intdiv($n, 2)] : intdiv($durations[$n / 2 - 1] + $durations[$n / 2], 2);
            $buckets = [
                ['max' => 60,     'key' => 'lt1m'],
                ['max' => 300,    'key' => 'm1_5'],
                ['max' => 900,    'key' => 'm5_15'],
                ['max' => 1800,   'key' => 'm15_30'],
                ['max' => 3600,   'key' => 'm30_60'],
                ['max' => 7200,   'key' => 'h1_2'],
                ['max' => 21600,  'key' => 'h2_6'],
                ['max' => null,   'key' => 'gt6'],
            ];
            $hist = array_fill_keys(array_column($buckets, 'key'), 0);
            foreach ($durations as $s) {
                foreach ($buckets as $b) {
                    if ($b['max'] === null || $s < $b['max']) { $hist[$b['key']]++; break; }
                }
            }
            $duration = [
                'count'     => $n,
                'mean_s'    => (int) round(array_sum($durations) / $n),
                'median_s'  => (int) $median,
                'min_s'     => $durations[0],
                'max_s'     => $durations[$n - 1],
                'histogram' => array_map(fn($k, $v) => ['key' => $k, 'count' => $v], array_keys($hist), array_values($hist)),
            ];
        }

        // --- Por equipo → encuestador: orden desc por volumen, top 20 + «otros». ---
        $byTeam = [];
        $teamOthers = 0;
        if ($teamField !== null) {
            // Convierte una hoja en su bloque de métricas. `$base` = denominador del % (el
            // equipo se mide sobre el total; el encuestador, sobre el total de su equipo).
            $finalize = function (array $leaf, int $base) use ($includeReview): array {
                $dur = null;
                if ($leaf['dur']) {
                    sort($leaf['dur']);
                    $n = count($leaf['dur']);
                    $median = $n % 2
                        ? $leaf['dur'][intdiv($n, 2)]
                        : intdiv($leaf['dur'][$n / 2 - 1] + $leaf['dur'][$n / 2], 2);
                    $dur = ['mean_s' => (int) round(array_sum($leaf['dur']) / $n), 'median_s' => (int) $median, 'count' => $n];
                }
                $out = [
                    'count'             => $leaf['count'],
                    'pct'               => $base > 0 ? round($leaf['count'] * 100 / $base, 1) : 0,
                    'duration'          => $dur,
                    'completeness_mean' => $leaf['compN'] > 0 ? round($leaf['compSum'] / $leaf['compN'], 4) : null,
                    'last_activity'     => $leaf['last'],
                ];
                if ($includeReview) $out['status'] = $leaf['status']; // mezcla de revisión: solo interna
                return $out;
            };

            uasort($teamAcc, fn($a, $b) => $b['leaf']['count'] <=> $a['leaf']['count']);
            $rank = 0;
            foreach ($teamAcc as $tKey => $t) {
                if ($rank++ >= 20) { $teamOthers += $t['leaf']['count']; continue; }
                $teamCount = $t['leaf']['count'];

                $enums = $t['enums'];
                uasort($enums, fn($a, $b) => $b['count'] <=> $a['count']);
                $eList = [];
                $eOthers = 0;
                $erank = 0;
                foreach ($enums as $eKey => $leaf) {
                    if ($erank++ >= 20) { $eOthers += $leaf['count']; continue; }
                    $eName = $eKey === '—' ? '—' : (($labelsOn && isset($enumOptMap[$eKey])) ? $enumOptMap[$eKey] : $eKey);
                    $eList[] = ['name' => $eName] + $finalize($leaf, $teamCount);
                }

                $tName = $tKey === '—' ? '—' : (($labelsOn && isset($teamOptMap[$tKey])) ? $teamOptMap[$tKey] : $tKey);
                // `key`: identificador URL-seguro para los checkboxes de filtro (código del
                // equipo o el centinela '__none__' del bucket «sin equipo»). El % del equipo
                // se mide sobre `$teamBase` (todos los equipos) para que sea estable al filtrar.
                $byTeam[] = ['name' => $tName, 'key' => ($tKey === '—' ? '__none__' : $tKey)]
                    + $finalize($t['leaf'], $teamBase) + [
                        'enumerators'       => $eList,
                        'enumerator_others' => $eOthers,
                    ];
            }
        }

        $out = [
            'total'           => $total,
            'base'            => $base,
            'filter'          => $filter,
            'last_submission' => $lastSub,
            'by_day'          => $byDay,
            'by_month'        => $byMonth,
            'period_granularity' => $periodGranularity,
            'trend'           => $trend,
            'by_question'     => $byQuestion,
            'by_enumerator'   => $byEnumerator,
            'enumerator_others' => $enumOthers,
            'duration'        => $duration,
            'by_hour'         => $byHour,
            'by_dow'          => $byDow,
            'timezone'        => Derived::tzMeta(),
            'attachments'     => [
                'with'    => $attWith,
                'without' => $base - $attWith,
                'with_pct'=> $base > 0 ? round($attWith * 100 / $base, 1) : 0,
                'by_kind' => $attByKind,
            ],
            'geo'             => [
                'with'    => $geoWith,
                'without' => $base - $geoWith,
                'with_pct'=> $base > 0 ? round($geoWith * 100 / $base, 1) : 0,
            ],
            'label_mode'      => Settings::labelMode(),
        ];
        if ($includeReview) {
            $out['by_status'] = $byStatus;
        }
        // Desglose por equipo (solo si está configurado y el campo es visible en este alcance).
        if ($teamField !== null) {
            $out['by_team']     = $byTeam;
            $out['team_others'] = $teamOthers;
            $out['team_field']  = [
                'key'   => $teamField,
                'label' => $labelsOn ? ($labels[$teamField] ?? $teamField) : $teamField,
            ];
            $out['enumerator_field'] = [
                'key'   => $enumPath,
                'label' => $enumIsField ? ($labelsOn ? ($labels[$enumPath] ?? $enumPath) : $enumPath) : null,
            ];
            // Selección de equipos activa (null = todos): el front la usa para marcar los
            // checkboxes tras recargar.
            $out['team_selection'] = is_array($teamSel) ? array_values(array_map('strval', $teamSel)) : null;
        }
        return $out;
    }
}
