<?php
/**
 * Detección del «meta-equipo» (campo de agrupación de equipos): el algoritmo de los
 * dos botones de la pantalla de ajustes. PROPONE y VALIDA, nunca dicta — el SELECT
 * del campo lo llenan todos los candidatos de tipo válido y el admin elige.
 *
 *   - suggest(): rankea los `select_one` visibles por el test de DEPENDENCIA
 *     FUNCIONAL `equipo → F` (muchos-a-uno: casi todos los equipos resuelven a un
 *     único valor de F) y «más grueso que el equipo» (menos valores distintos que
 *     equipos). El encuestador y los campos más finos se descartan solos (baja
 *     consistencia).
 *   - check(): para el campo ELEGIDO, toma el valor dominante por equipo y lista los
 *     conflictos (equipos que caen en >1 valor) como aviso de calidad de dato.
 *
 * Con pocos datos ambas responden `insufficient: true` con el detalle: informan, no
 * bloquean. Respetan el scoping por filas y el ocultado de columnas del usuario
 * (un usuario con «Ajustes» y alcance restringido no infiere sobre filas que no ve).
 */
class TeamGroups {

    /** Mínimo de equipos con datos para que la inferencia diga algo. */
    private const MIN_TEAMS = 2;

    /**
     * Recuento base compartido: por CANDIDATO, votos `equipo => valor => nº envíos`.
     *
     * @param string[] $candidates Rutas a contar (ya filtradas por visibilidad).
     * @return array{votes: array, teams: array<string,true>, rows: int}
     */
    private static function tally(
        int $formId,
        ?array $scope,
        ?array $fieldScope,
        ?array $schemaRaw,
        string $teamField,
        array $candidates
    ): array {
        [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

        $votes = [];   // field => team => value => count
        $teams = [];   // team => true (equipos con algún envío)
        $rows  = 0;
        foreach (DB::stream(
            "SELECT json_payload FROM submissions_cache WHERE form_id = ? AND $scopeSql",
            array_merge([$formId], $scopeP)
        ) as $r) {
            $payload = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schemaRaw);
            $rows++;

            $tvRaw = $payload[$teamField] ?? null;
            if ($tvRaw === null || $tvRaw === '' || is_array($tvRaw)) continue; // sin equipo: no vota
            $tKey = (string) $tvRaw;
            $teams[$tKey] = true;

            foreach ($candidates as $f) {
                $v = $payload[$f] ?? null;
                if ($v === null || $v === '' || is_array($v)) continue;
                $votes[$f][$tKey][(string) $v] = ($votes[$f][$tKey][(string) $v] ?? 0) + 1;
            }
        }
        return ['votes' => $votes, 'teams' => $teams, 'rows' => $rows];
    }

    /**
     * «Detectar meta-equipos»: candidatos `select_one` rankeados por dependencia
     * funcional equipo → F y grosor. Devuelve métricas por candidato para que la UI
     * las muestre; no excluye a nadie del SELECT.
     */
    public static function suggest(
        int $formId,
        ?array $schemaRaw,
        ?array $scope,
        ?array $fieldScope,
        string $locale,
        string $teamField,
        ?string $enumField
    ): array {
        $resolved = FormSchema::resolve($schemaRaw, $locale);
        $labels   = $resolved['labels'] ?? [];

        // Candidatos a rankear: select_one visibles, distintos de equipo y encuestador
        // (los guards del SELECT). El SELECT de la UI admite además texto monovaluado;
        // el ranking se limita a select_one porque la dependencia funcional sobre texto
        // libre es ruido (grafías) — el admin puede elegirlo igualmente.
        $candidates = [];
        foreach (($schemaRaw['fields'] ?? []) as $path => $fd) {
            $path = (string) $path;
            // El tipo normalizado puede llevar la lista pegada («select_one provs»).
            if (!str_starts_with((string) ($fd['type'] ?? ''), 'select_one') || !empty($fd['multi'])) continue;
            if ($path === $teamField || $path === $enumField) continue;
            if (FieldScope::isHidden($fieldScope, $path)) continue;
            $candidates[] = $path;
        }
        if (!$candidates) {
            return ['insufficient' => true, 'reason' => 'no_candidates', 'candidates' => []];
        }

        $t = self::tally($formId, $scope, $fieldScope, $schemaRaw, $teamField, $candidates);
        $teamsTotal = count($t['teams']);
        if ($teamsTotal < self::MIN_TEAMS) {
            return ['insufficient' => true, 'reason' => 'not_enough_teams',
                    'teams' => $teamsTotal, 'rows' => $t['rows'], 'candidates' => []];
        }

        $out = [];
        foreach ($candidates as $f) {
            $perTeam = $t['votes'][$f] ?? [];
            $covered = count($perTeam);            // equipos con algún valor de F
            if ($covered === 0) continue;          // sin datos: no se puede rankear
            $consistent = 0;                       // equipos que resuelven a UN único valor
            $distinct = [];                        // valores dominantes distintos de F
            foreach ($perTeam as $vals) {
                if (count($vals) === 1) $consistent++;
                arsort($vals);
                $distinct[(string) array_key_first($vals)] = true;
            }
            $out[] = [
                'field'           => $f,
                'label'           => $labels[$f] ?? $f,
                'teams_covered'   => $covered,
                'teams_total'     => $teamsTotal,
                'consistency_pct' => round($consistent * 100 / $covered, 4),
                'distinct_values' => count($distinct),
                // «Más grueso que el equipo»: agrupa de verdad (menos valores que equipos).
                'coarser'         => count($distinct) < $teamsTotal,
            ];
        }
        if (!$out) {
            return ['insufficient' => true, 'reason' => 'no_data',
                    'teams' => $teamsTotal, 'rows' => $t['rows'], 'candidates' => []];
        }

        // Ranking: primero los que agrupan (más gruesos que el equipo), por consistencia
        // desc; a igualdad, más cobertura y menos valores (agrupación más clara).
        usort($out, fn($a, $b) =>
            [$b['coarser'], $b['consistency_pct'], $b['teams_covered'], $a['distinct_values']]
            <=> [$a['coarser'], $a['consistency_pct'], $a['teams_covered'], $b['distinct_values']]);

        return ['insufficient' => false, 'teams' => $teamsTotal, 'rows' => $t['rows'], 'candidates' => $out];
    }

    /**
     * «Detectar problemas»: para el campo elegido, valor dominante por equipo y lista
     * de conflictos (equipos repartidos entre >1 valor) como aviso de calidad de dato.
     */
    public static function check(
        int $formId,
        ?array $schemaRaw,
        ?array $scope,
        ?array $fieldScope,
        string $locale,
        string $teamField,
        string $groupField
    ): array {
        $resolved = FormSchema::resolve($schemaRaw, $locale);
        $labelsOn = Settings::labelMode() === 'labels';
        $options  = $resolved['options'] ?? [];
        $teamOpts = $options[$teamField] ?? [];
        $valOpts  = $options[$groupField] ?? [];
        $name = fn(array $opts, string $code): string => ($labelsOn && isset($opts[$code])) ? $opts[$code] : $code;

        $t = self::tally($formId, $scope, $fieldScope, $schemaRaw, $teamField, [$groupField]);
        $teamsTotal = count($t['teams']);
        if ($teamsTotal === 0) {
            return ['insufficient' => true, 'reason' => 'no_data', 'rows' => $t['rows'],
                    'conflicts' => [], 'unassigned' => [], 'teams' => 0];
        }

        $perTeam = $t['votes'][$groupField] ?? [];
        $conflicts  = [];  // equipos repartidos entre >1 valor
        $unassigned = [];  // equipos con envíos pero SIN valor del campo (caerían en «Sin agrupar»)
        foreach (array_keys($t['teams']) as $tKey) {
            $tKey = (string) $tKey;
            $vals = $perTeam[$tKey] ?? [];
            if (!$vals) {
                $unassigned[] = ['team' => $tKey, 'name' => $name($teamOpts, $tKey)];
                continue;
            }
            if (count($vals) === 1) continue;
            ksort($vals, SORT_STRING);
            arsort($vals); // dominante primero (empate → alfabético, determinista)
            $list = [];
            foreach ($vals as $code => $c) {
                $list[] = ['value' => (string) $code, 'label' => $name($valOpts, (string) $code), 'count' => $c];
            }
            $conflicts[] = [
                'team'     => $tKey,
                'name'     => $name($teamOpts, $tKey),
                'dominant' => $list[0]['value'],
                'values'   => $list,
            ];
        }
        // Los conflictos más repartidos primero (más envíos fuera del dominante).
        usort($conflicts, function ($a, $b) {
            $strays = fn($c) => array_sum(array_column($c['values'], 'count')) - $c['values'][0]['count'];
            return $strays($b) <=> $strays($a);
        });

        return [
            'insufficient' => false,
            'teams'        => $teamsTotal,
            'rows'         => $t['rows'],
            'conflicts'    => $conflicts,
            'unassigned'   => $unassigned,
        ];
    }
}
