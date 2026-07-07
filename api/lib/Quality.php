<?php
/**
 * Control de calidad por equipo/encuestador sobre `submissions_cache`.
 *
 * Evalúa cada envío contra los umbrales admisibles del formulario (columnas
 * `forms.qc_min_duration` / `qc_max_duration` / `qc_min_gap`, en minutos) y
 * agrupa las infracciones por equipo → encuestador, con el mismo par de campos
 * configurable del desglose de estadísticas (`stats_team_field` /
 * `stats_enumerator_field`). Cuatro banderas:
 *
 *   - short     : duración < qc_min_duration (encuesta sospechosamente corta).
 *   - long      : duración > qc_max_duration (NULL = sin tope).
 *   - short_gap : hueco entre el FIN de una encuesta y el INICIO de la siguiente
 *                 del mismo encuestador < qc_min_gap.
 *   - overlap   : hueco NEGATIVO (la siguiente empezó antes de acabar la anterior;
 *                 señal de fabricación). Se marca SIEMPRE, sin umbral configurable.
 *   - duplicate : otro envío del FORMULARIO (de cualquier encuestador) tiene
 *                 exactamente las mismas respuestas (solo campos de datos; los
 *                 envíos sin ninguna respuesta no cuentan). Sin umbral: una copia
 *                 exacta casi siempre es una encuesta duplicada/fabricada.
 *   - gps       : el MISMO punto GPS exacto se repite en ≥ GPS_MIN_REPEATS envíos
 *                 del mismo encuestador («GPS clavado», señal de relleno desde un
 *                 mismo sitio). Solo participa lo que TIENE coordenadas: sin datos
 *                 geo la señal queda inactiva (`gps_enabled = false`) y los envíos
 *                 sin punto no forman grupo.
 *
 * La duración y el hueco salen de las claves meta `start`/`end` del esquema (las
 * mismas del orden por duración de la tabla): epochs vía Derived::ts, así que la
 * resta es correcta sea cual sea la zona del dispositivo. La CADENA de
 * consecutividad se construye por (equipo, encuestador) —la misma jerarquía que
 * se muestra—, ordenando por `start`; el hueco se mide contra el MÁXIMO `end`
 * visto hasta entonces en la cadena (si una encuesta engloba a otra, la tercera
 * se compara contra el fin real más tardío, no contra el de la englobada). Los
 * envíos sin start/end válidos no son evaluables: cuentan aparte (`untimed`) y
 * no participan en la cadena.
 *
 * ALCANCE por estado de revisión (`$scopeStatuses`, del ajuste global `qc_scope`):
 * decide qué envíos se REPORTAN y se cuentan (por defecto solo pendientes/en
 * espera: los aprobados/rechazados ya pasaron revisión humana). La cadena de
 * consecutividad se construye SIEMPRE sobre todos los envíos del encuestador —
 * la física de huecos/solapes no depende del estado de revisión: una pendiente
 * que solapa con una aprobada se marca igual (contra su fin real), aunque la
 * aprobada no aparezca en la lista.
 *
 * Es un ANÁLISIS de solo lectura: no escribe banderas en ningún sitio. El marcado
 * en lote «en espera» lo hace el flujo de revisión existente (review_batch), con
 * el admin que pulsa como atribución. Respeta RowScope y FieldScope igual que
 * lib/Stats (mismo gating de visibilidad del campo de equipo/encuestador).
 */
class Quality {

    /** Las banderas, en el orden canónico de la UI. */
    public const FLAGS = ['short', 'long', 'short_gap', 'overlap', 'duplicate', 'gps'];

    /** Repeticiones del mismo punto exacto que convierten el grupo en «GPS clavado». */
    public const GPS_MIN_REPEATS = 3;

    /**
     * @param int         $formId      Formulario.
     * @param array|null  $schemaRaw   Esquema XLSForm normalizado (forms.schema_json) o null.
     * @param array|null  $scope       Regla RowScope ya normalizada (o null = sin restricción).
     * @param array|null  $fieldScope  Regla FieldScope ya normalizada (o null).
     * @param string      $locale      Idioma para resolver etiquetas de equipo/encuestador.
     * @param string|null $teamField   forms.stats_team_field (NULL = sin nivel de equipo).
     * @param string|null $enumField   forms.stats_enumerator_field (NULL/`_submitted_by` =
     *                                 usar el usuario Kobo que envió).
     * @param int|null    $minDuration forms.qc_min_duration (minutos; NULL = sin comprobación).
     * @param int|null    $maxDuration forms.qc_max_duration (minutos; NULL = sin tope).
     * @param int|null    $minGap      forms.qc_min_gap (minutos; NULL = sin comprobación;
     *                                 el solape se marca igualmente).
     * @param array|null  $scopeStatuses Estados de revisión cuyos envíos se REPORTAN
     *                                 (p. ej. ['pending','on_hold']). NULL = todos.
     *                                 No afecta a la cadena de consecutividad.
     */
    public static function compute(
        int $formId,
        ?array $schemaRaw,
        ?array $scope,
        ?array $fieldScope,
        string $locale,
        ?string $teamField,
        ?string $enumField,
        ?int $minDuration,
        ?int $maxDuration,
        ?int $minGap,
        ?array $scopeStatuses = null
    ): array {
        [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

        // Mismo gating que lib/Stats: no se agrupa por un campo oculto en este alcance.
        $teamField = ($teamField !== null && $teamField !== '' && !FieldScope::isHidden($fieldScope, $teamField))
            ? $teamField : null;
        $enumIsField = $enumField !== null && $enumField !== '' && $enumField !== '_submitted_by'
            && !FieldScope::isHidden($fieldScope, $enumField);
        $enumPath = $enumIsField ? $enumField : '_submitted_by';

        // Etiquetas legibles de equipo/encuestador (según el modo de etiquetas global).
        $resolved   = FormSchema::resolve($schemaRaw, $locale);
        $labelsOn   = Settings::labelMode() === 'labels';
        $labels     = $resolved['labels'] ?? [];
        $options    = $resolved['options'] ?? [];
        $teamOptMap = $teamField !== null ? ($options[$teamField] ?? []) : [];
        $enumOptMap = $enumIsField ? ($options[$enumPath] ?? []) : [];

        // Claves meta start/end del esquema (con respaldo de convención), como el orden
        // por duración de la tabla de envíos.
        $startKey = $schemaRaw['meta']['start'] ?? 'start';
        $endKey   = $schemaRaw['meta']['end'] ?? 'end';

        // Firma de respuestas para la señal de DUPLICADOS: solo CONTENIDO. Con
        // esquema, sus rutas exactas; sin esquema, todo lo que no sea metadato
        // (claves `_*`, `meta/…`, `formhub/…`, start/end/today). Los campos de
        // equipo/encuestador se excluyen (identifican, no son contenido: así una
        // copia entre encuestadores distintos también se detecta). Devuelve null
        // con MENOS de 2 respuestas no vacías: un envío casi vacío no es evidencia
        // (en un formulario de una sola pregunta, coincidir no significa nada).
        $schemaPaths  = array_keys($schemaRaw['fields'] ?? []);
        $sigExclude   = array_fill_keys(array_filter([$teamField, $enumIsField ? $enumPath : null]), true);
        $signature = function (array $payload) use ($schemaPaths, $sigExclude, $startKey, $endKey): ?string {
            $answers = [];
            if ($schemaPaths) {
                foreach ($schemaPaths as $p) {
                    if (isset($sigExclude[$p])) continue;
                    $v = $payload[$p] ?? null;
                    if ($v === null || (is_string($v) && trim($v) === '') || $v === []) continue;
                    $answers[$p] = $v;
                }
            } else {
                foreach ($payload as $k => $v) {
                    $k = (string) $k;
                    if (isset($sigExclude[$k])) continue;
                    if (str_starts_with($k, '_') || str_starts_with($k, 'meta/') || str_starts_with($k, 'formhub/')) continue;
                    if ($k === $startKey || $k === $endKey || $k === 'today') continue;
                    if ($v === null || (is_string($v) && trim($v) === '') || $v === []) continue;
                    $answers[$k] = $v;
                }
            }
            if (count($answers) < 2) return null;
            ksort($answers);
            return md5(json_encode($answers, JSON_UNESCAPED_UNICODE));
        };

        // --- Pasada única en STREAMING: derivar tiempos y agrupar por (equipo,
        // encuestador). El estado de revisión sale de la columna desnormalizada
        // review_status (sin materializar el log de revisiones), y las filas se
        // recorren sin búfer (DB::stream) para que la memoria no dependa del tamaño
        // del formulario. Se agrupa TODO (la cadena de consecutividad necesita todos
        // los envíos del encuestador); `in` marca si el envío está dentro del alcance
        // por estado y por tanto se cuenta/reporta.
        $groups   = []; // tKey => eKey => [entradas]
        $total    = 0;  // envíos EN ALCANCE
        $untimed  = 0;  // envíos EN ALCANCE sin tiempos válidos
        $received = 0;  // TODOS los envíos recibidos (en el RowScope del usuario)
        $sigCount = []; // firma de respuestas => nº de envíos (duplicados, a nivel de formulario)
        $gpsCount = []; // tKey => eKey => "lat,lng" => nº de envíos (GPS clavado, por encuestador)
        $gpsSeen  = false; // ¿algún envío con coordenadas? (si no, la señal GPS queda inactiva)
        foreach (DB::stream(
            "SELECT submission_uid, json_payload, submitted_at, review_status FROM submissions_cache
             WHERE form_id = ? AND $scopeSql",
            array_merge([$formId], $scopeP)
        ) as $r) {
            $received++;
            $payload = json_decode($r['json_payload'], true) ?: [];
            $startTs = Derived::ts($payload[$startKey] ?? null);
            $endTs   = Derived::ts($payload[$endKey] ?? null);
            $dur     = ($startTs !== null && $endTs !== null && $endTs >= $startTs) ? $endTs - $startTs : null;
            $st      = in_array($r['review_status'], ValidationStatus::STATUSES, true) ? $r['review_status'] : 'pending';
            $in      = $scopeStatuses === null || in_array($st, $scopeStatuses, true);
            if ($in) {
                $total++;
                if ($dur === null) $untimed++;
            }

            $tv = $teamField !== null ? ($payload[$teamField] ?? null) : null;
            $ev = $payload[$enumPath] ?? null;
            $tKey = ($tv === null || $tv === '' || is_array($tv)) ? '—' : (string) $tv;
            $eKey = ($ev === null || $ev === '' || is_array($ev)) ? '—' : (string) $ev;

            // Señales de fabricación: firma de respuestas (duplicados) y punto GPS
            // exacto (clavado). Los envíos sin respuestas / sin coordenadas no
            // participan (null): un vacío nunca forma grupo.
            $sig = $signature($payload);
            if ($sig !== null) {
                $sigCount[$sig] = ($sigCount[$sig] ?? 0) + 1;
            }
            $gps = null;
            if (($pt = Geo::primaryPoint($payload, $schemaRaw)) !== null) {
                $gps = $pt[0] . ',' . $pt[1];
                $gpsCount[$tKey][$eKey][$gps] = ($gpsCount[$tKey][$eKey][$gps] ?? 0) + 1;
                $gpsSeen = true;
            }

            $groups[$tKey][$eKey][] = [
                'uid'          => $r['submission_uid'],
                'submitted_at' => $r['submitted_at'],
                'start'        => $startTs,
                'end'          => $endTs,
                'dur'          => $dur,
                'st'           => $st,
                'in'           => $in,
                'sig'          => $sig,
                'gps'          => $gps,
            ];
        }

        $minDurS = $minDuration !== null ? $minDuration * 60 : null;
        $maxDurS = $maxDuration !== null ? $maxDuration * 60 : null;
        $minGapS = $minGap !== null ? $minGap * 60 : null;

        $zeroFlags  = array_fill_keys(self::FLAGS, 0);
        $flagsTotal = $zeroFlags;
        $flaggedTotal = 0;
        $weekAgg    = []; // 'YYYY-Www' => ['total' => n, 'flagged' => n] (tendencia)

        $teams = [];
        foreach ($groups as $tKey => $enums) {
            $teamOut = [
                'name'        => $tKey === '—' ? '—'
                    : (($labelsOn && isset($teamOptMap[$tKey])) ? $teamOptMap[$tKey] : $tKey),
                'count'       => 0,  // envíos EN ALCANCE
                'total'       => 0,  // TODOS los envíos recibidos del equipo (contexto del alcance)
                'flagged'     => 0,
                'flags'       => $zeroFlags,
                'enumerators' => [],
            ];

            foreach ($enums as $eKey => $entries) {
                // El total del equipo cuenta TODOS sus envíos, incluso los de
                // encuestadores que luego no se listan por no tener nada en alcance.
                $teamOut['total'] += count($entries);

                // Cadena de consecutividad: TODOS los envíos con tiempos (en alcance o
                // no), por `start` ascendente. El alcance no altera la física del hueco.
                $timed = array_values(array_filter($entries, fn($e) => $e['dur'] !== null));
                usort($timed, fn($a, $b) => [$a['start'], $a['end'], $a['uid']] <=> [$b['start'], $b['end'], $b['uid']]);
                $gaps = [];          // uid => hueco (s) respecto al máximo `end` anterior
                $prevEndMax = null;
                foreach ($timed as $e) {
                    if ($prevEndMax !== null) $gaps[$e['uid']] = $e['start'] - $prevEndMax;
                    $prevEndMax = $prevEndMax === null ? $e['end'] : max($prevEndMax, $e['end']);
                }

                // Banderas por envío: se calculan SIEMPRE (también fuera del alcance por
                // estado y en encuestadores que luego no se listan): la tendencia semanal
                // mide la física de todo lo recibido; el alcance solo decide qué se REPORTA.
                $flagged = []; // uid => banderas
                foreach ($entries as $e) {
                    $flags = [];
                    if ($e['dur'] !== null) {
                        if ($minDurS !== null && $e['dur'] < $minDurS) $flags[] = 'short';
                        if ($maxDurS !== null && $e['dur'] > $maxDurS) $flags[] = 'long';
                        $gap = $gaps[$e['uid']] ?? null;
                        if ($gap !== null) {
                            if ($gap < 0) $flags[] = 'overlap';
                            elseif ($minGapS !== null && $gap < $minGapS) $flags[] = 'short_gap';
                        }
                    }
                    if ($e['sig'] !== null && ($sigCount[$e['sig']] ?? 0) >= 2) $flags[] = 'duplicate';
                    if ($e['gps'] !== null && ($gpsCount[$tKey][$eKey][$e['gps']] ?? 0) >= self::GPS_MIN_REPEATS) $flags[] = 'gps';
                    if ($flags) $flagged[$e['uid']] = $flags;

                    // Tendencia: % con bandera por semana ISO (días UTC, como submitted_at).
                    if ($e['submitted_at'] !== null) {
                        $wk = (new DateTime($e['submitted_at'], new DateTimeZone('UTC')))->format('o-\WW');
                        $weekAgg[$wk]['total']   = ($weekAgg[$wk]['total'] ?? 0) + 1;
                        $weekAgg[$wk]['flagged'] = ($weekAgg[$wk]['flagged'] ?? 0) + ($flags ? 1 : 0);
                    }
                }

                // Sin envíos en alcance, el encuestador no aparece (sus envíos ya
                // participaron como vecinos de cadena y en la tendencia).
                $inCount = count(array_filter($entries, fn($e) => $e['in']));
                if ($inCount === 0) continue;

                $enumOut = [
                    'name'       => $eKey === '—' ? '—'
                        : (($labelsOn && isset($enumOptMap[$eKey])) ? $enumOptMap[$eKey] : $eKey),
                    'count'      => $inCount,        // en alcance
                    'total'      => count($entries), // todos los recibidos del encuestador
                    'flagged'    => 0,
                    'flags'      => $zeroFlags,
                    'violations' => [],
                ];

                foreach ($entries as $e) {
                    if (!$e['in']) continue; // fuera del alcance: no se reporta
                    $flags = $flagged[$e['uid']] ?? [];
                    if (!$flags) continue;

                    $enumOut['flagged']++;
                    foreach ($flags as $f) $enumOut['flags'][$f]++;
                    $enumOut['violations'][] = [
                        'uid'           => $e['uid'],
                        'submitted_at'  => $e['submitted_at'],
                        'start_at'      => Derived::formatLocal($e['start']),
                        'end_at'        => Derived::formatLocal($e['end']),
                        'duration_s'    => $e['dur'],
                        'gap_s'         => $gaps[$e['uid']] ?? null,
                        'flags'         => $flags,
                        'review_status' => $e['st'],
                    ];
                }

                $teamOut['count']   += $enumOut['count'];
                $teamOut['flagged'] += $enumOut['flagged'];
                foreach (self::FLAGS as $f) $teamOut['flags'][$f] += $enumOut['flags'][$f];
                $teamOut['enumerators'][] = $enumOut;
            }

            // Equipos sin ningún envío en alcance tampoco aparecen.
            if (!$teamOut['enumerators']) continue;

            // Encuestadores con más infracciones primero (a igualdad, más envíos).
            usort($teamOut['enumerators'], fn($a, $b) => [$b['flagged'], $b['count']] <=> [$a['flagged'], $a['count']]);
            $flaggedTotal += $teamOut['flagged'];
            foreach (self::FLAGS as $f) $flagsTotal[$f] += $teamOut['flags'][$f];
            $teams[] = $teamOut;
        }
        usort($teams, fn($a, $b) => [$b['flagged'], $b['count']] <=> [$a['flagged'], $a['count']]);

        // Tendencia: % de envíos con alguna bandera por semana ISO, sobre TODO lo
        // recibido (la física de las banderas no depende del alcance por estado).
        ksort($weekAgg);
        $byWeek = [];
        foreach ($weekAgg as $wk => $agg) {
            $byWeek[] = [
                'week'    => $wk,
                'total'   => $agg['total'],
                'flagged' => $agg['flagged'],
                'pct'     => $agg['total'] > 0 ? round($agg['flagged'] * 100 / $agg['total'], 4) : 0,
            ];
        }

        $out = [
            'total'      => $total,
            // TODOS los envíos recibidos (en el RowScope del usuario, sin filtrar por
            // estado de revisión): denominador del «{flagged} / {received}» de la UI.
            'received'   => $received,
            'untimed'    => $untimed,
            'flagged'    => $flaggedTotal,
            'flags'      => $flagsTotal,
            'by_week'    => $byWeek,
            // Señal «GPS clavado»: activa solo si algún envío trae coordenadas.
            'gps_enabled'     => $gpsSeen,
            'gps_min_repeats' => self::GPS_MIN_REPEATS,
            'thresholds' => [
                'min_duration' => $minDuration,
                'max_duration' => $maxDuration,
                'min_gap'      => $minGap,
            ],
            'teams'      => $teams,
            'timezone'   => Derived::tzMeta(),
            'label_mode' => Settings::labelMode(),
            // NULL = sin nivel de equipo (la UI muestra solo encuestadores, en un único grupo).
            'team_field' => $teamField !== null ? [
                'key'   => $teamField,
                'label' => $labelsOn ? ($labels[$teamField] ?? $teamField) : $teamField,
            ] : null,
            'enumerator_field' => [
                'key'   => $enumPath,
                'label' => $enumIsField ? ($labelsOn ? ($labels[$enumPath] ?? $enumPath) : $enumPath) : null,
            ],
        ];
        return $out;
    }
}
