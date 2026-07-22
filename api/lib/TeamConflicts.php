<?php
/**
 * Resolución de incongruencias EQUIPO ↔ META-EQUIPO (hito QC, jul-2026): equipos
 * cuyos envíos apuntan a más de un valor del campo de agrupación (`team_group_field`).
 *
 * Dirección por defecto: se corrige el EQUIPO dando por bueno el META-EQUIPO — el
 * meta-equipo suele ser un `select_one` (elegido, no tecleado), así que es el lado
 * fiable; el código de equipo (texto libre) es el que se equivoca. El meta-equipo
 * solo no determina el equipo (una provincia tiene varios), pero el CÓDIGO DE
 * ENCUESTADOR sí (misma dependencia funcional encuestador → equipo): el desempate
 * busca a qué equipo del meta-equipo del envío pertenece el encuestador, casando el
 * valor con la normalización/alias de 1.46.0 (clave aquí: el campo de encuestador es
 * el que suele ser texto libre). Corregir el meta-equipo queda como opción manual
 * para el caso raro (mal clic en el select), expuesta caso a caso por la UI.
 *
 * plan() PROPONE, nunca escribe: calcula los conflictos, los envíos descarriados
 * (casos) y una sugerencia por caso según el modo del formulario
 * (`forms.team_conflict_mode`). La escritura va SIEMPRE por el endpoint de
 * aplicación (confirmada por el usuario, tanda a tanda) sobre el flujo de edición
 * real (lib/SubmissionEdit). Respeta RowScope y FieldScope como TeamGroups.
 */
class TeamConflicts {

    /**
     * Modos del ajuste «Resolución de incongruencias equipo ↔ meta-equipo»:
     *   - 'approx'        → automático, mejor aproximación: desempate por encuestador;
     *                       lo no resuelto cae a confirmación particular.
     *   - 'first'         → automático: primer equipo (alfabético) del meta-equipo correcto.
     *   - 'least'         → automático: equipo con menos encuestas del meta-equipo correcto.
     *   - 'confirm_group' → semi-automático: un modal elige UN equipo para todos los
     *                       casos de ese meta-equipo.
     *   - 'confirm_each'  → confirmación particular: modal por caso.
     * Los automáticos exigen confirmación de RESUMEN por tanda en la UI (nunca
     * escritura invisible) y el disparo es siempre manual desde la tarjeta del QC.
     */
    public const MODES = ['approx', 'first', 'least', 'confirm_group', 'confirm_each'];

    /** Tope de casos devueltos por plan(): una tanda se resuelve y se recarga. */
    public const MAX_CASES = 500;

    /** Modo validado del formulario (inválido/NULL → 'approx', el modo 1 del diseño). */
    public static function mode(?string $value): string {
        return in_array($value, self::MODES, true) ? $value : 'approx';
    }

    /**
     * Plan de resolución: conflictos, casos (envíos descarriados) con su sugerencia
     * según el modo, y los equipos candidatos por meta-equipo (para los modales).
     *
     * @return array{mode:string, teams:int, rows:int, conflict_teams:array,
     *               cases:array, cases_truncated:int, meta_groups:array}
     */
    public static function plan(
        int $formId,
        ?array $schemaRaw,
        ?array $scope,
        ?array $fieldScope,
        string $locale,
        string $teamField,
        ?string $enumField,
        string $groupField,
        string $mode
    ): array {
        $mode = self::mode($mode);
        [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

        $resolvedSchema = FormSchema::resolve($schemaRaw, $locale);
        $labelsOn = Settings::labelMode() === 'labels';
        $options  = $resolvedSchema['options'] ?? [];
        $teamOpts = $options[$teamField] ?? [];
        $metaOpts = $options[$groupField] ?? [];

        // Mismo eje de encuestador que lib/Quality: el campo configurado si es visible,
        // con respaldo en el metadato _submitted_by.
        $enumIsField = $enumField !== null && $enumField !== '' && $enumField !== '_submitted_by'
            && !FieldScope::isHidden($fieldScope, $enumField);
        $enumPath = $enumIsField ? $enumField : '_submitted_by';
        $enumOpts = $enumIsField ? ($options[$enumPath] ?? []) : [];

        // Normalización de ejes de texto libre (forms.member_normalize; lib/MemberNorm),
        // como en Quality/Stats. Matiz propio: el ENCUESTADOR se pliega GLOBALMENTE (no
        // dentro de su equipo) — el desempate persigue a la persona a través de los
        // equipos, que es justo lo que la clave compuesta de las vistas impide.
        $normMode = MemberNorm::mode(
            DB::run('SELECT member_normalize FROM forms WHERE id = ?', [$formId])->fetch()['member_normalize'] ?? null
        );
        $resolver = MemberNorm::resolver($normMode, $formId);
        $normTeam = $normMode !== 'raw' && !$teamOpts;
        $normEnum = $normMode !== 'raw' && !$enumOpts;

        $rows      = []; // [uid, submitted_at, tKey, mVal, eKey, eRaw] (solo filas con equipo Y meta)
        $votes     = []; // tKey => meta => nº envíos
        $teamTotal = []; // tKey => envíos totales del equipo (para el modo 'least')
        $teamSpell = []; $teamCanon = []; // tKey => [grafía => n] ; tKey => canónico (alias)
        $scanned   = 0;

        foreach (DB::stream(
            "SELECT submission_uid, json_payload, submitted_at FROM submissions_cache
             WHERE form_id = ? AND $scopeSql",
            array_merge([$formId], $scopeP)
        ) as $r) {
            $payload = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schemaRaw);
            $scanned++;

            $tv = $payload[$teamField] ?? null;
            if ($tv === null || $tv === '' || is_array($tv)) continue; // sin equipo: nada que corregir
            $tKey = (string) $tv;
            if ($normTeam) {
                $rt = $resolver('team', $tKey);
                $teamSpell[$rt['key']][$tKey] = ($teamSpell[$rt['key']][$tKey] ?? 0) + 1;
                if ($rt['canon'] !== null) $teamCanon[$rt['key']] = $rt['canon'];
                $tKey = $rt['key'];
            }
            $teamTotal[$tKey] = ($teamTotal[$tKey] ?? 0) + 1;

            $mv   = $payload[$groupField] ?? null;
            $mVal = ($mv === null || $mv === '' || is_array($mv)) ? null : (string) $mv;
            if ($mVal === null) continue; // sin meta: «sin agrupar», no es un conflicto
            $votes[$tKey][$mVal] = ($votes[$tKey][$mVal] ?? 0) + 1;

            $ev   = $payload[$enumPath] ?? null;
            $eRaw = ($ev === null || $ev === '' || is_array($ev)) ? null : (string) $ev;
            $eKey = $eRaw !== null ? ($normEnum ? $resolver('member', $eRaw)['key'] : $eRaw) : null;

            $rows[] = [$r['submission_uid'], $r['submitted_at'], $tKey, $mVal, $eKey, $eRaw];
        }

        // Valor dominante por equipo (mayoría; empate → alfabético, determinista como
        // TeamGroups::check) y equipos en conflicto (repartidos entre >1 valor).
        $dominant = []; $conflicted = [];
        foreach ($votes as $tKey => $vals) {
            ksort($vals, SORT_STRING);
            arsort($vals);
            $dominant[$tKey] = (string) array_key_first($vals);
            if (count($vals) > 1) $conflicted[$tKey] = true;
        }

        // Etiqueta visible y VALOR DE ESCRITURA por equipo. En select_one el código ya
        // es canónico (se escribe el código, se muestra la etiqueta); en texto libre se
        // escribe la grafía canónica del cubo (alias o la más frecuente), que es
        // también la etiqueta.
        $teamWrite = fn(string $tKey): string => $normTeam
            ? (MemberNorm::pickLabel($teamSpell[$tKey] ?? [], $teamCanon[$tKey] ?? null) ?: $tKey)
            : $tKey;
        $teamLabel = fn(string $tKey): string => $normTeam
            ? $teamWrite($tKey)
            : (($labelsOn && isset($teamOpts[$tKey])) ? $teamOpts[$tKey] : $tKey);
        $metaLabel = fn(string $m): string => ($labelsOn && isset($metaOpts[$m])) ? $metaOpts[$m] : $m;

        // Equipos candidatos por meta-equipo: los que RESUELVEN a ese valor (su
        // dominante), en conflicto o no. Orden estable por etiqueta (es el orden del
        // modo 'first' y el de los modales).
        $teamsOfMeta = []; // meta => [tKey, ...]
        foreach ($dominant as $tKey => $m) {
            $teamsOfMeta[$m][] = $tKey;
        }
        foreach ($teamsOfMeta as &$list) {
            usort($list, fn($a, $b) => [$teamLabel($a), $a] <=> [$teamLabel($b), $b]);
        }
        unset($list);

        // Dependencia funcional encuestador → equipo, SOLO desde filas consistentes
        // (meta == dominante de su equipo): las descarriadas llevan el equipo mal
        // tecleado y no enseñan pertenencia.
        $enumTeams = []; // eKey => tKey => nº envíos
        foreach ($rows as [$uid, $ts, $tKey, $mVal, $eKey]) {
            if ($eKey === null || $mVal !== $dominant[$tKey]) continue;
            $enumTeams[$eKey][$tKey] = ($enumTeams[$eKey][$tKey] ?? 0) + 1;
        }

        // Casos: envíos de equipos en conflicto cuyo meta NO es el dominante del
        // equipo. Cada caso lleva su sugerencia según el modo (solo automáticos).
        $cases = []; $truncated = 0;
        foreach ($rows as [$uid, $ts, $tKey, $mVal, $eKey, $eRaw]) {
            if (!isset($conflicted[$tKey]) || $mVal === $dominant[$tKey]) continue;
            if (count($cases) >= self::MAX_CASES) { $truncated++; continue; }

            $cand = $teamsOfMeta[$mVal] ?? []; // el propio equipo nunca está (su dominante ≠ mVal)
            $suggestion = null;
            if ($mode !== 'first' && $mode !== 'least' && $eKey !== null) {
                // Desempate por encuestador: en 'approx' es lo que la tanda escribe;
                // en los modos de confirmación es la opción PREseleccionada del modal
                // (sugerencia, no escritura — el usuario decide).
                // El equipo del meta correcto donde ese encuestador tiene más envíos
                // consistentes; empate real → sin sugerencia (en 'approx' cae a
                // confirmación particular, nunca se adivina).
                $best = null; $bestN = 0; $tie = false;
                foreach ($cand as $c) {
                    $n = $enumTeams[$eKey][$c] ?? 0;
                    if ($n > $bestN) { $best = $c; $bestN = $n; $tie = false; }
                    elseif ($n === $bestN && $n > 0) { $tie = true; }
                }
                if ($best !== null && !$tie) {
                    $suggestion = ['value' => $teamWrite($best), 'label' => $teamLabel($best), 'via' => 'enumerator'];
                }
            } elseif ($mode === 'first' && $cand) {
                $suggestion = ['value' => $teamWrite($cand[0]), 'label' => $teamLabel($cand[0]), 'via' => 'first'];
            } elseif ($mode === 'least' && $cand) {
                $best = $cand[0];
                foreach ($cand as $c) {
                    if ($teamTotal[$c] < $teamTotal[$best]) $best = $c;
                }
                $suggestion = ['value' => $teamWrite($best), 'label' => $teamLabel($best), 'via' => 'least'];
            }

            $cases[] = [
                'uid'          => $uid,
                'submitted_at' => $ts,
                'team'         => ['value' => $teamWrite($tKey), 'label' => $teamLabel($tKey)],
                'meta'         => ['value' => $mVal, 'label' => $metaLabel($mVal)],
                // Meta dominante del equipo tecleado: la opción manual rara «corregir
                // el meta-equipo» (mal clic en el select) escribe este valor.
                'dominant'     => ['value' => $dominant[$tKey], 'label' => $metaLabel($dominant[$tKey])],
                'enumerator'   => $eRaw !== null
                    ? ['value' => $eRaw, 'label' => ($labelsOn && isset($enumOpts[$eRaw])) ? $enumOpts[$eRaw] : $eRaw]
                    : null,
                'suggestion'   => $suggestion,
            ];
        }

        // Resumen por equipo en conflicto (la tarjeta del QC): valores con el
        // dominante primero, ordenados por nº de descarriados (como el chequeo).
        $conflictTeams = [];
        foreach (array_keys($conflicted) as $tKey) {
            $vals = $votes[$tKey];
            ksort($vals, SORT_STRING);
            arsort($vals);
            $list = [];
            foreach ($vals as $m => $n) {
                $list[] = ['value' => (string) $m, 'label' => $metaLabel((string) $m), 'count' => $n];
            }
            $conflictTeams[] = [
                'team'     => ['value' => $teamWrite($tKey), 'label' => $teamLabel($tKey)],
                'dominant' => ['value' => $dominant[$tKey], 'label' => $metaLabel($dominant[$tKey])],
                'values'   => $list,
                'strays'   => array_sum(array_column($list, 'count')) - $list[0]['count'],
            ];
        }
        usort($conflictTeams, fn($a, $b) => $b['strays'] <=> $a['strays']);

        // Grupos por meta-equipo con casos (los modales de confirmación general y
        // particular eligen entre estos equipos candidatos).
        $metaGroups = [];
        foreach ($cases as $c) {
            $m = $c['meta']['value'];
            if (!isset($metaGroups[$m])) {
                $metaGroups[$m] = [
                    'value' => $m,
                    'label' => $c['meta']['label'],
                    'cases' => 0,
                    'teams' => array_map(fn($tk) => [
                        'value' => $teamWrite($tk), 'label' => $teamLabel($tk), 'count' => $teamTotal[$tk],
                    ], $teamsOfMeta[$m] ?? []),
                ];
            }
            $metaGroups[$m]['cases']++;
        }

        return [
            'mode'            => $mode,
            'teams'           => count($teamTotal),
            'rows'            => $scanned,
            'conflict_teams'  => $conflictTeams,
            'cases'           => $cases,
            'cases_truncated' => $truncated,
            'meta_groups'     => array_values($metaGroups),
        ];
    }
}
