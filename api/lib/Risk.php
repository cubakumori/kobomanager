<?php
/**
 * Índice de riesgo por encuestador y equipo sobre `submissions_cache`.
 *
 * Detección HEURÍSTICA de fabricación de encuestas («curbstoning»). A diferencia
 * de las banderas por-envío de lib/Quality (física de tiempos/duplicados/GPS), aquí
 * se agregan señales RELATIVAS A LOS PARES en un índice que prioriza a quién hacer
 * back-check (re-entrevista de verificación). Es OPT-IN: solo se computa si el
 * formulario define `forms.risk_min_n` (N mínimo de encuestas por encuestador/equipo
 * para puntuar); NULL = índice desactivado.
 *
 * PRINCIPIOS (ver ROADMAP «Índice de riesgo»):
 *   - Por encuestador Y por equipo. El de equipo NO es la media de sus miembros (eso
 *     diluye al tramposo): es «cuántos superan el umbral de sospecha + cuál es el peor»
 *     (+ señales de equipo genuinas, Fase 1 tardía). El centrado de los z es ROBUSTO
 *     (mediana + MAD, no media/desviación): así el propio tramposo no infla su base.
 *   - Nunca un score opaco: cada componente viaja con su valor real, la mediana del
 *     equipo (pares) y su z, para que la UI lo explique en lenguaje llano. El índice es
 *     una prioridad, SIEMPRE junto a sus componentes.
 *   - «Datos insuficientes» por debajo del N mínimo (no se puntúa). Cada métrica tiene
 *     además su propio gate de volumen (percentmatch exige ≥2 envíos con contenido).
 *
 * PARES: los z se calculan contra los COMPAÑEROS DE EQUIPO puntuables (si no hay campo
 * de equipo, contra todos los encuestadores del formulario).
 *
 * PUENTE CON EL QC (desde 1.50.0): la métrica `qc_flag_rate` trae la física de las
 * banderas de lib/Quality al índice — tasa de envíos con bandera CONTABLE
 * (Quality::RISK_FLAGS: corta + solape entre consecutivas + duplicada) sobre los envíos
 * en alcance. No se recomputa aquí: el llamador computa Quality y pasa
 * Quality::riskRates() como $qcRates (par (equipo, encuestador) plegado con normKey,
 * mismo emparejamiento que admissiblePendingUids). Al z-scorearse contra pares, el
 * ruido sistémico de los apagones (a todos se les cuelgan formularios) se cancela.
 *
 * ALCANCE por estado de revisión (`$scopeStatuses`, del ajuste `qc_scope`, igual que
 * lib/Quality): decide qué envíos ALIMENTAN las señales y el índice (por defecto
 * pending/on_hold; los aprobados/rechazados ya pasaron revisión humana). La MEZCLA de
 * estado (`status`) que viaja por encuestador/equipo cuenta SIEMPRE todos los recibidos
 * (contexto del compañero «resumen de estado»): un aprobado/rechazado no desaparece.
 *
 * Solo lectura. Respeta RowScope y FieldScope igual que lib/Stats (FieldScope::apply
 * por fila: los campos ocultos no entran en las firmas ni en las señales).
 */
class Risk {

    /** Tope de envíos por encuestador para percentmatch (acota el O(n²); recorte reportado). */
    public const PM_SAMPLE = 200;

    /** Similitud a partir de la cual un par cuenta como «casi idéntico» (drill-down). */
    public const PM_PAIR_THRESHOLD = 0.95;

    /** z del índice a partir del cual un encuestador se considera «sospechoso» (equipo/nivel). */
    public const SUSPICION_Z = 2.0;

    /** Cortes de nivel del índice/componentes (z): [elevated, high]. */
    public const LEVEL_ELEVATED = 1.0;
    public const LEVEL_HIGH     = 2.0;

    /** Gates de volumen por métrica. */
    public const SL_MIN_FIELDS   = 3;   // select_one respondidas por envío para puntuar straight-lining
    public const BENFORD_MIN     = 30;  // valores numéricos para evaluar Benford
    public const DIST_MIN_POOL   = 20;  // envíos en el pool por campo para comparar distribución
    public const GPS_MIN_POINTS  = 5;   // puntos con coordenadas para medir dispersión
    public const GPS_MAX_POINTS  = 500; // tope de puntos retenidos por encuestador

    /** Distribución de Benford del primer dígito significativo (1–9). */
    private const BENFORD = [
        1 => 0.301, 2 => 0.176, 3 => 0.125, 4 => 0.097, 5 => 0.079,
        6 => 0.067, 7 => 0.058, 8 => 0.051, 9 => 0.046,
    ];

    /**
     * Registro de métricas del índice, en orden de presentación. Cada entrada:
     *   - weight : peso en el índice combinado (percentmatch dominante).
     *   - dir    : 'high' (solo un valor ALTO es sospechoso), 'low' (solo BAJO) o 'abs'
     *              (ambos extremos se desvían de los pares).
     * El z y el índice son agnósticos de la métrica; añadir una señal = una entrada aquí
     * más su cálculo del valor bruto en computeRawMetrics().
     */
    private const METRICS = [
        'percentmatch'  => ['weight' => 1.0, 'dir' => 'high'],
        'straightlining'=> ['weight' => 0.6, 'dir' => 'high'],
        'distribution'  => ['weight' => 0.6, 'dir' => 'high'],
        'skip_rate'     => ['weight' => 0.4, 'dir' => 'abs'],
        'benford'       => ['weight' => 0.5, 'dir' => 'high'],
        'productivity'  => ['weight' => 0.4, 'dir' => 'high'],
        'gps_cluster'   => ['weight' => 0.5, 'dir' => 'low'],
        // Tasa de banderas del Control de calidad (envíos con bandera contable /
        // envíos en alcance; Quality::RISK_FLAGS = corta + solape entre consecutivas
        // + duplicada — sin las ruidosas en apagones ni GPS, ya cubierta por
        // gps_cluster). El valor NO se recomputa aquí: llega vía $qcRates desde los
        // conteos que lib/Quality ya calcula. Al ser relativa a pares, el ruido
        // sistémico (a todos se les cuelgan formularios) se cancela.
        'qc_flag_rate'  => ['weight' => 0.6, 'dir' => 'high'],
    ];

    /**
     * @param int         $formId        Formulario.
     * @param array|null  $schemaRaw     Esquema XLSForm normalizado (forms.schema_json) o null.
     * @param array|null  $scope         Regla RowScope ya normalizada (o null = sin restricción).
     * @param array|null  $fieldScope    Regla FieldScope ya normalizada (o null).
     * @param string      $locale        Idioma para etiquetas de equipo/encuestador.
     * @param string|null $teamField     forms.stats_team_field (NULL = sin nivel de equipo).
     * @param string|null $enumField     forms.stats_enumerator_field (NULL/`_submitted_by`).
     * @param int|null    $minN          forms.risk_min_n (NULL = índice desactivado).
     * @param array|null  $scopeStatuses Estados que ALIMENTAN el índice (NULL = todos).
     * @param array|null  $qcRates       Salida de Quality::riskRates() — tasa de banderas
     *                                 contables por par (equipo, encuestador) plegado —
     *                                 para la métrica qc_flag_rate. NULL = métrica
     *                                 inactiva (el llamador no computó el QC). Debe
     *                                 venir del MISMO alcance ($scope/$scopeStatuses)
     *                                 para que numerador y pares sean coherentes.
     */
    public static function compute(
        int $formId,
        ?array $schemaRaw,
        ?array $scope,
        ?array $fieldScope,
        string $locale,
        ?string $teamField,
        ?string $enumField,
        ?int $minN,
        ?array $scopeStatuses = null,
        ?array $qcRates = null
    ): array {
        // Mismo gating que lib/Stats/lib/Quality: no se agrupa por un campo oculto.
        $teamField = ($teamField !== null && $teamField !== '' && !FieldScope::isHidden($fieldScope, $teamField))
            ? $teamField : null;
        $enumIsField = $enumField !== null && $enumField !== '' && $enumField !== '_submitted_by'
            && !FieldScope::isHidden($fieldScope, $enumField);
        $enumPath = $enumIsField ? $enumField : '_submitted_by';

        $resolved   = FormSchema::resolve($schemaRaw, $locale);
        $labelsOn   = Settings::labelMode() === 'labels';
        $labels     = $resolved['labels'] ?? [];
        $options    = $resolved['options'] ?? [];
        $teamOptMap = $teamField !== null ? ($options[$teamField] ?? []) : [];
        $enumOptMap = $enumIsField ? ($options[$enumPath] ?? []) : [];

        // Normalización de ejes de TEXTO LIBRE (forms.member_normalize; ver el bloque
        // homólogo de lib/Quality): clave plegada, etiqueta = grafía más frecuente (o
        // canónico del alias); select_one = no-op; el miembro se fusiona DENTRO de su
        // equipo. Para el Índice es doblemente importante: al fusionar variantes, el
        // encuestador alcanza su `min_n` real y los cohortes de pares dejan de estar
        // contaminados por «medio-encuestadores».
        $normMode = MemberNorm::mode(
            DB::run('SELECT member_normalize FROM forms WHERE id = ?', [$formId])->fetch()['member_normalize'] ?? null
        );
        $normResolver = MemberNorm::resolver($normMode, $formId);
        $normTeam  = $normMode !== 'raw' && $teamField !== null && !$teamOptMap;
        $normEnum  = $normMode !== 'raw' && !$enumOptMap;
        $teamSpell = []; $teamCanon = [];
        $enumSpell = []; $enumCanon = [];

        $teamMeta = $teamField !== null ? [
            'key'   => $teamField,
            'label' => $labelsOn ? ($labels[$teamField] ?? $teamField) : $teamField,
        ] : null;
        $enumMeta = [
            'key'   => $enumPath,
            'label' => $enumIsField ? ($labelsOn ? ($labels[$enumPath] ?? $enumPath) : $enumPath) : null,
        ];

        // Índice desactivado: nada que puntuar. La UI muestra el estado vacío que
        // invita a definir `risk_min_n`.
        if ($minN === null) {
            return [
                'enabled'          => false,
                'min_n'            => null,
                'received'         => 0,
                'scored'           => 0,
                'insufficient'     => 0,
                'suspicion_z'      => self::SUSPICION_Z,
                'signals'          => [],
                'teams'            => [],
                'timezone'         => Derived::tzMeta(),
                'label_mode'       => Settings::labelMode(),
                'team_field'       => $teamMeta,
                'enumerator_field' => $enumMeta,
            ];
        }

        // Campos de CONTENIDO para percentmatch (y futuras firmas): con esquema, sus
        // rutas de datos salvo geo; sin esquema, se resuelve por payload. Se excluyen
        // siempre los campos de equipo/encuestador (identifican, no son contenido).
        $exclude = array_fill_keys(array_filter([$teamField, $enumIsField ? $enumPath : null]), true);
        $geoPaths = array_fill_keys(Geo::geoFieldPaths($schemaRaw), true);
        // Clasificación de campos del esquema: contenido (percentmatch/skip), select_one
        // (straight-lining/distribución) y numéricos (Benford). Sin esquema, solo hay
        // contenido dinámico por payload; el resto de señales quedan inactivas.
        $schemaContentPaths = null;
        $selectOnePaths = [];
        $numericPaths   = [];
        if ($schemaRaw && !empty($schemaRaw['fields'])) {
            $schemaContentPaths = [];
            foreach ($schemaRaw['fields'] as $p => $fd) {
                if (isset($exclude[$p]) || isset($geoPaths[$p])) continue;
                $schemaContentPaths[] = $p;
                $type = (string) ($fd['type'] ?? '');
                if (str_starts_with($type, 'select_one')) $selectOnePaths[] = $p;
                elseif (in_array($type, ['integer', 'decimal', 'range'], true)) $numericPaths[] = $p;
            }
        }
        $contentFieldCount = $schemaContentPaths !== null ? count($schemaContentPaths) : 0;
        $startKey = $schemaRaw['meta']['start'] ?? 'start';
        $endKey   = $schemaRaw['meta']['end'] ?? 'end';

        // --- Pasada única en streaming: agrupar por (equipo, encuestador), acumular la
        // mezcla de estado (todos los recibidos) y las señales en alcance. Casi todas son
        // agregables online; solo se RETIENE la muestra de percentmatch (≤ PM_SAMPLE) y
        // los puntos GPS (≤ GPS_MAX_POINTS). La caché se recorre sin búfer (DB::stream).
        [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');
        $groups    = []; // tKey => eKey => acumulador
        $poolDist  = []; // campo select_one => valor => nº (pool de TODO en alcance)
        $received  = 0;
        $gpsSeen   = false;

        foreach (DB::stream(
            "SELECT json_payload, review_status, submitted_at FROM submissions_cache
             WHERE form_id = ? AND $scopeSql",
            array_merge([$formId], $scopeP)
        ) as $r) {
            $received++;
            $payload = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schemaRaw);
            $st = in_array($r['review_status'], ValidationStatus::STATUSES, true) ? $r['review_status'] : 'pending';
            $in = $scopeStatuses === null || in_array($st, $scopeStatuses, true);

            $tv = $teamField !== null ? ($payload[$teamField] ?? null) : null;
            $ev = $payload[$enumPath] ?? null;
            $tKey = ($tv === null || $tv === '' || is_array($tv)) ? '—' : (string) $tv;
            $eKey = ($ev === null || $ev === '' || is_array($ev)) ? '—' : (string) $ev;
            if ($normTeam && $tKey !== '—') {
                $rt = $normResolver('team', $tKey);
                $teamSpell[$rt['key']][$tKey] = ($teamSpell[$rt['key']][$tKey] ?? 0) + 1;
                if ($rt['canon'] !== null) $teamCanon[$rt['key']] = $rt['canon'];
                $tKey = $rt['key'];
            }
            if ($normEnum && $eKey !== '—') {
                $re = $normResolver('member', $eKey);
                $enumSpell[$tKey][$re['key']][$eKey] = ($enumSpell[$tKey][$re['key']][$eKey] ?? 0) + 1;
                if ($re['canon'] !== null) $enumCanon[$tKey][$re['key']] = $re['canon'];
                $eKey = $re['key'];
            }

            if (!isset($groups[$tKey][$eKey])) $groups[$tKey][$eKey] = self::newAcc();
            $g = &$groups[$tKey][$eKey];
            $g['received']++;
            $g['status'][$st]++;
            if (!$in) { unset($g); continue; }
            $g['in']++;

            // percentmatch: vector de respuestas de contenido (muestra acotada).
            $vec = self::answerVector($payload, $schemaContentPaths, $exclude, $geoPaths, $startKey, $endKey);
            if (count($vec) > 0) {
                $g['pm_total']++;
                if (count($g['pm']) < self::PM_SAMPLE) $g['pm'][] = $vec;
            }

            // skip / «no sabe»: huecos + respuestas de no-respuesta sobre el nº de campos
            // de contenido del esquema (sin esquema no hay denominador → señal inactiva).
            if ($contentFieldCount > 0) {
                $answered = 0; $dk = 0;
                foreach ($schemaContentPaths as $p) {
                    $nv = self::normValue($payload[$p] ?? null);
                    if ($nv === null) continue;
                    $answered++;
                    if (self::isDontKnow($nv)) $dk++;
                }
                $g['skip_missing'] += ($contentFieldCount - $answered);
                $g['skip_dk']      += $dk;
                $g['skip_cells']   += $contentFieldCount;
            }

            // straight-lining: entre las select_one respondidas (≥ SL_MIN_FIELDS), fracción
            // que comparte la opción más común. Distribución vs pool: cuentas por campo.
            if ($selectOnePaths) {
                $vals = [];
                foreach ($selectOnePaths as $p) {
                    $nv = self::normValue($payload[$p] ?? null);
                    if ($nv === null) continue;
                    $vals[] = $nv;
                    $g['dist'][$p][$nv] = ($g['dist'][$p][$nv] ?? 0) + 1;
                    $poolDist[$p][$nv]  = ($poolDist[$p][$nv] ?? 0) + 1;
                }
                if (count($vals) >= self::SL_MIN_FIELDS) {
                    $freq = array_count_values($vals);
                    $g['sl_sum'] += max($freq) / count($vals);
                    $g['sl_n']++;
                }
            }

            // Benford: primer dígito significativo de cada valor numérico.
            foreach ($numericPaths as $p) {
                $d = self::firstDigit($payload[$p] ?? null);
                if ($d !== null) { $g['benford'][$d]++; $g['benford_n']++; }
            }

            // Productividad: entrevistas por día activo (día natural UTC de submitted_at).
            if ($r['submitted_at'] !== null) {
                $g['days'][substr((string) $r['submitted_at'], 0, 10)] = true;
            }

            // GPS clustering: puntos con coordenadas (retención acotada).
            if (($pt = Geo::primaryPoint($payload, $schemaRaw)) !== null) {
                $gpsSeen = true;
                if (count($g['gps']) < self::GPS_MAX_POINTS) $g['gps'][] = $pt;
                $g['gps_total']++;
            }
            unset($g);
        }

        // Etiquetas resueltas de equipo/encuestador (mismo criterio que lib/Quality):
        // se usan tanto en la salida como para casar el par con Quality::riskRates
        // (ambos módulos resuelven idéntico; el plegado normKey hace la clave estable).
        $teamName = fn(string $tKey): string => $tKey === '—' ? '—'
            : ($normTeam
                ? (MemberNorm::pickLabel($teamSpell[$tKey] ?? [], $teamCanon[$tKey] ?? null) ?: $tKey)
                : (($labelsOn && isset($teamOptMap[$tKey])) ? $teamOptMap[$tKey] : $tKey));
        $enumName = fn(string $tKey, string $eKey): string => $eKey === '—' ? '—'
            : ($normEnum
                ? (MemberNorm::pickLabel($enumSpell[$tKey][$eKey] ?? [], $enumCanon[$tKey][$eKey] ?? null) ?: $eKey)
                : (($labelsOn && isset($enumOptMap[$eKey])) ? $enumOptMap[$eKey] : $eKey));

        // --- Métricas brutas por encuestador ---
        // Estructura de trabajo: tKey => eKey => ['received','in','status','metrics'=>[k=>info]].
        // De paso, se agrega la distribución de respuestas por EQUIPO (suma de miembros)
        // para la señal genuina de equipo (equipo vs pool de todos los equipos).
        $enumMetrics = [];
        $metricsSeen = [];
        $teamDistAcc = []; // tKey => campo => valor => nº
        foreach ($groups as $tKey => $enums) {
            foreach ($enums as $eKey => $g) {
                if ($g['in'] === 0) continue; // nada en alcance → fuera del índice
                $metrics = self::computeRawMetrics($g, $poolDist);
                // Tasa de banderas del QC: no se recomputa — se busca el par resuelto
                // en el mapa que trae el llamador (Quality::riskRates).
                if ($qcRates !== null) {
                    $pk = MemberNorm::normKey($teamName($tKey)) . "\0" . MemberNorm::normKey($enumName($tKey, $eKey));
                    $qr = $qcRates[$pk] ?? null;
                    if ($qr !== null && $qr['total'] > 0) {
                        $metrics['qc_flag_rate'] = [
                            'value' => round($qr['flagged'] / $qr['total'], 4),
                            'extra' => ['flagged' => $qr['flagged'], 'submissions' => $qr['total']],
                        ];
                    }
                }
                foreach ($metrics as $k => $_) $metricsSeen[$k] = true;
                foreach ($g['dist'] as $field => $counts) {
                    foreach ($counts as $val => $c) {
                        $teamDistAcc[$tKey][$field][$val] = ($teamDistAcc[$tKey][$field][$val] ?? 0) + $c;
                    }
                }
                $enumMetrics[$tKey][$eKey] = [
                    'received' => $g['received'],
                    'in'       => $g['in'],
                    'status'   => $g['status'],
                    'metrics'  => $metrics,
                ];
            }
        }

        // Señal de equipo: TVD de la distribución del equipo vs el pool de todos los
        // equipos, z-scoreada ENTRE equipos (no la media de sus miembros).
        $teamDistVal = [];
        foreach ($teamDistAcc as $tKey => $acc) {
            if (($m = self::distribution($acc, $poolDist)) !== null) $teamDistVal[$tKey] = $m;
        }
        $teamDistPeer = null;
        if ($teamDistVal) {
            $vals = array_map(fn($m) => $m['value'], $teamDistVal);
            $med  = self::median($vals);
            $teamDistPeer = ['median' => $med, 'mad' => self::mad($vals, $med)];
        }

        // --- z robusto vs pares (compañeros de equipo puntuables) + índice combinado ---
        $scoredTotal = 0;
        $insufTotal  = 0;
        $teams = [];
        foreach ($enumMetrics as $tKey => $enums) {
            // Encuestadores puntuables del equipo = base de pares (in >= minN).
            $scoredKeys = array_keys(array_filter($enums, fn($e) => $e['in'] >= $minN));

            // Mediana + MAD por métrica sobre los pares puntuables que la tienen definida.
            $peerStats = [];
            foreach (self::METRICS as $mKey => $mDef) {
                $vals = [];
                foreach ($scoredKeys as $eKey) {
                    if (isset($enums[$eKey]['metrics'][$mKey])) {
                        $vals[] = $enums[$eKey]['metrics'][$mKey]['value'];
                    }
                }
                if ($vals) {
                    $median = self::median($vals);
                    $peerStats[$mKey] = ['median' => $median, 'mad' => self::mad($vals, $median)];
                }
            }

            $teamOut = [
                'name'         => $teamName($tKey),
                'count'        => 0,
                'received'     => 0,
                'scored'       => 0,
                'insufficient' => 0,
                'index'        => null,
                'harbors'      => ['over_threshold' => 0, 'worst_index' => null, 'worst_name' => null],
                'status'       => array_fill_keys(ValidationStatus::STATUSES, 0),
                'components'    => [], // señales de nivel de equipo (distribución vs pool)
                'enumerators'  => [],
            ];
            if (isset($teamDistVal[$tKey]) && $teamDistPeer !== null) {
                $info = $teamDistVal[$tKey];
                $z = self::zScore($info['value'], $teamDistPeer, 'high');
                $teamOut['components'][] = array_merge([
                    'key'         => 'team_distribution',
                    'value'       => $info['value'],
                    'peer_median' => $teamDistPeer['median'],
                    'z'           => round($z, 3),
                    'level'       => self::level($z),
                ], $info['extra'] ?? []);
            }

            foreach ($enums as $eKey => $e) {
                $name = $enumName($tKey, $eKey);
                $scored = $e['in'] >= $minN;

                $components = [];
                $index = null;
                if ($scored) {
                    $index = 0.0;
                    foreach (self::METRICS as $mKey => $mDef) {
                        if (!isset($e['metrics'][$mKey]) || !isset($peerStats[$mKey])) continue;
                        $info = $e['metrics'][$mKey];
                        $z = self::zScore($info['value'], $peerStats[$mKey], $mDef['dir']);
                        $index += $mDef['weight'] * max(0.0, $z); // solo lo sospechoso suma
                        $components[] = array_merge([
                            'key'         => $mKey,
                            'value'       => $info['value'],
                            'peer_median' => $peerStats[$mKey]['median'],
                            'z'           => round($z, 3),
                            'level'       => self::level($z),
                        ], $info['extra'] ?? []);
                    }
                    $index = round($index, 3);
                }

                $teamOut['count']    += $e['in'];
                $teamOut['received'] += $e['received'];
                foreach (ValidationStatus::STATUSES as $s) $teamOut['status'][$s] += $e['status'][$s];
                if ($scored) {
                    $teamOut['scored']++;
                    $scoredTotal++;
                    if ($index >= self::SUSPICION_Z) $teamOut['harbors']['over_threshold']++;
                    if ($teamOut['harbors']['worst_index'] === null || $index > $teamOut['harbors']['worst_index']) {
                        $teamOut['harbors']['worst_index'] = $index;
                        $teamOut['harbors']['worst_name']  = $name;
                    }
                } else {
                    $teamOut['insufficient']++;
                    $insufTotal++;
                }

                $teamOut['enumerators'][] = [
                    'name'         => $name,
                    'count'        => $e['in'],
                    'received'     => $e['received'],
                    'insufficient' => !$scored,
                    'index'        => $index,
                    'status'       => $e['status'],
                    'components'   => $components,
                ];
            }

            // Índice del equipo = prioridad del peor miembro (no la media). El «alberga
            // sospechosos» viaja aparte en `harbors`.
            $teamOut['index'] = $teamOut['harbors']['worst_index'];
            // Puntuables primero, luego por índice desc; insuficientes al final.
            // Último criterio: nombre asc — los empates no deben depender del orden
            // físico de las filas en la BD (cambia con los índices del esquema).
            usort($teamOut['enumerators'], fn($a, $b) =>
                [$b['insufficient'] ? 0 : 1, $b['index'] ?? -1, $a['name']] <=> [$a['insufficient'] ? 0 : 1, $a['index'] ?? -1, $b['name']]);
            $teams[] = $teamOut;
        }
        // Equipos por índice desc (los sin puntuar, al final); nombre asc como desempate.
        usort($teams, fn($a, $b) => [$b['index'] ?? -1, $b['count'], $a['name']] <=> [$a['index'] ?? -1, $a['count'], $b['name']]);

        // Estado de cada señal: activa si algún encuestador la produjo; si no, el motivo
        // (falta de esquema / de datos) para que la UI explique por qué no aparece.
        $ctx = [
            'schema'    => $schemaContentPaths !== null,
            'select_one'=> $selectOnePaths !== [],
            'numeric'   => $numericPaths !== [],
            'gps'       => $gpsSeen,
        ];
        $signals = [];
        foreach (self::METRICS as $mKey => $_) {
            $active = isset($metricsSeen[$mKey]);
            $signals[] = ['key' => $mKey, 'active' => $active,
                          'reason' => $active ? null : self::inactiveReason($mKey, $ctx)];
        }
        // Señal de nivel de equipo (no está en METRICS: no es por-encuestador).
        $signals[] = ['key' => 'team_distribution', 'active' => $teamDistVal !== [],
                      'reason' => $teamDistVal !== [] ? null : ($ctx['select_one'] ? 'insufficient_data' : 'no_select_one')];

        return [
            'enabled'          => true,
            'min_n'            => $minN,
            'received'         => $received,
            'scored'           => $scoredTotal,
            'insufficient'     => $insufTotal,
            'suspicion_z'      => self::SUSPICION_Z,
            'signals'          => $signals,
            'teams'            => $teams,
            'timezone'         => Derived::tzMeta(),
            'label_mode'       => Settings::labelMode(),
            'team_field'       => $teamMeta,
            'enumerator_field' => $enumMeta,
        ];
    }

    /**
     * Vector de respuestas de CONTENIDO de un envío (ruta => valor normalizado), sin
     * vacíos. Con `$schemaContentPaths` (esquema), esas rutas; sin esquema, todo lo que
     * no sea metadato / equipo / encuestador / geo / start-end-today. Es la unidad de
     * comparación de percentmatch.
     */
    private static function answerVector(
        array $payload, ?array $schemaContentPaths, array $exclude, array $geoPaths,
        string $startKey, string $endKey
    ): array {
        $vec = [];
        if ($schemaContentPaths !== null) {
            foreach ($schemaContentPaths as $p) {
                $n = self::normValue($payload[$p] ?? null);
                if ($n !== null) $vec[$p] = $n;
            }
        } else {
            foreach ($payload as $k => $v) {
                $k = (string) $k;
                if (isset($exclude[$k]) || isset($geoPaths[$k])) continue;
                if (FormSchema::isMetadataField($k)) continue;
                if ($k === $startKey || $k === $endKey || $k === 'today') continue;
                $n = self::normValue($v);
                if ($n !== null) $vec[$k] = $n;
            }
        }
        return $vec;
    }

    /** Normaliza un valor a string comparable, o null si «no respondido». */
    private static function normValue($v): ?string {
        if ($v === null) return null;
        if (is_array($v)) {
            $t = array_map(fn($x) => trim((string) $x), $v);
            $t = array_values(array_filter($t, fn($x) => $x !== ''));
            if (!$t) return null;
            sort($t); // select_multiple: orden irrelevante
            return implode(' ', $t);
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** Acumulador vacío de un encuestador para la pasada de streaming. */
    private static function newAcc(): array {
        return [
            'received'     => 0,
            'in'           => 0,
            'status'       => array_fill_keys(ValidationStatus::STATUSES, 0),
            'pm'           => [],   // muestra de vectores de respuesta (percentmatch)
            'pm_total'     => 0,    // envíos con contenido vistos (recorte del muestreo)
            'sl_sum'       => 0.0,  // suma de puntuaciones straight-lining por envío
            'sl_n'         => 0,    // envíos con ≥ SL_MIN_FIELDS select_one respondidas
            'skip_missing' => 0,    // huecos (campos de contenido sin respuesta)
            'skip_dk'      => 0,    // respuestas de «no sabe / no responde»
            'skip_cells'   => 0,    // celdas de contenido evaluadas (denominador)
            'dist'         => [],   // campo select_one => valor => nº (distribución propia)
            'benford'      => array_fill(1, 9, 0), // primer dígito significativo => nº
            'benford_n'    => 0,
            'days'         => [],   // 'Y-m-d' => true (días activos)
            'gps'          => [],   // [lat,lng] retenidos (≤ GPS_MAX_POINTS)
            'gps_total'    => 0,
        ];
    }

    /**
     * Métricas brutas de un encuestador a partir de su acumulador de la pasada. Devuelve
     * map metricKey => ['value' => float, 'extra' => array] con solo las que superan su
     * gate de volumen (las demás quedan «n/d» y no puntúan).
     *
     * @param array $poolDist campo select_one => valor => nº en TODO el pool en alcance.
     */
    private static function computeRawMetrics(array $g, array $poolDist): array {
        $out = [];
        if (($m = self::percentMatch($g['pm'], $g['pm_total'])) !== null)       $out['percentmatch']   = $m;
        if (($m = self::straightLining($g['sl_sum'], $g['sl_n'])) !== null)     $out['straightlining'] = $m;
        if (($m = self::distribution($g['dist'], $poolDist)) !== null)          $out['distribution']   = $m;
        if (($m = self::skipRate($g['skip_missing'], $g['skip_dk'], $g['skip_cells'])) !== null) $out['skip_rate'] = $m;
        if (($m = self::benford($g['benford'], $g['benford_n'])) !== null)      $out['benford']        = $m;
        if (($m = self::productivity($g['in'], count($g['days']))) !== null)    $out['productivity']   = $m;
        if (($m = self::gpsCluster($g['gps'], $g['gps_total'])) !== null)       $out['gps_cluster']    = $m;
        return $out;
    }

    /** Motivo por el que una señal no se computó (para la UI). */
    private static function inactiveReason(string $mKey, array $ctx): string {
        switch ($mKey) {
            case 'percentmatch': return 'no_repeated_content';
            case 'straightlining':
            case 'distribution': return $ctx['select_one'] ? 'insufficient_data' : 'no_select_one';
            case 'skip_rate':    return $ctx['schema'] ? 'insufficient_data' : 'no_schema';
            case 'benford':      return $ctx['numeric'] ? 'no_numeric_volume' : 'no_numeric';
            case 'gps_cluster':  return $ctx['gps'] ? 'insufficient_data' : 'no_geo';
            default:             return 'insufficient_data';
        }
    }

    /**
     * straight-lining: media de la fracción de select_one que comparten la MISMA opción
     * dentro de cada envío (patrón de rellenar «en línea recta»). 1.0 = siempre la misma.
     * @return array|null ['value','extra'=>['submissions']] o null (< 1 envío evaluable).
     */
    private static function straightLining(float $sum, int $n): ?array {
        if ($n < 1) return null;
        return ['value' => round($sum / $n, 4), 'extra' => ['submissions' => $n]];
    }

    /**
     * distribución vs pool: media de la distancia de variación total (TVD ∈ [0,1]) entre
     * la distribución de respuestas del encuestador y la del POOL, por cada campo
     * select_one con pool suficiente. Alto = responde muy distinto al conjunto.
     * @return array|null ['value','extra'=>['fields']] o null (ningún campo con pool).
     */
    private static function distribution(array $enumDist, array $poolDist): ?array {
        $tvds = [];
        foreach ($enumDist as $field => $counts) {
            $pool = $poolDist[$field] ?? [];
            $poolTot = array_sum($pool);
            $enumTot = array_sum($counts);
            if ($poolTot < self::DIST_MIN_POOL || $enumTot === 0) continue;
            $vals = array_unique(array_merge(array_keys($counts), array_keys($pool)));
            $tvd = 0.0;
            foreach ($vals as $v) {
                $pe = ($counts[$v] ?? 0) / $enumTot;
                $pp = ($pool[$v] ?? 0) / $poolTot;
                $tvd += abs($pe - $pp);
            }
            $tvds[] = $tvd / 2; // TVD = ½·Σ|p−q|
        }
        if (!$tvds) return null;
        return ['value' => round(array_sum($tvds) / count($tvds), 4), 'extra' => ['fields' => count($tvds)]];
    }

    /**
     * skip / «no sabe»: (huecos + no-respuestas) / celdas de contenido evaluadas. Dos
     * caras: muy alto (encuestador vago) o muy bajo (rellena TODO, sospechoso) frente a
     * los pares. @return array|null ['value','extra'=>['dk','missing']] o null (sin celdas).
     */
    private static function skipRate(int $missing, int $dk, int $cells): ?array {
        if ($cells <= 0) return null;
        return ['value' => round(($missing + $dk) / $cells, 4), 'extra' => ['dk' => $dk, 'missing' => $missing]];
    }

    /**
     * Benford: TVD entre la distribución observada del primer dígito significativo de los
     * valores numéricos y la ley de Benford. Alto = dígitos «demasiado humanos».
     * @return array|null ['value','extra'=>['n']] o null (< BENFORD_MIN valores).
     */
    private static function benford(array $digits, int $n): ?array {
        if ($n < self::BENFORD_MIN) return null;
        $tvd = 0.0;
        for ($d = 1; $d <= 9; $d++) {
            $tvd += abs($digits[$d] / $n - self::BENFORD[$d]);
        }
        return ['value' => round($tvd / 2, 4), 'extra' => ['n' => $n]];
    }

    /** Productividad: entrevistas por día activo. Alto = ritmo implausible vs pares. */
    private static function productivity(int $in, int $days): ?array {
        if ($days < 1) return null;
        return ['value' => round($in / $days, 3), 'extra' => ['days' => $days]];
    }

    /**
     * GPS clustering: distancia media (m) de los puntos del encuestador a su centroide.
     * BAJA = puntos apiñados (relleno desde un sitio), sospechoso → dir 'low'.
     * @return array|null ['value','extra'=>['points']] o null (< GPS_MIN_POINTS).
     */
    private static function gpsCluster(array $points, int $total): ?array {
        $n = count($points);
        if ($n < self::GPS_MIN_POINTS) return null;
        $lat = 0.0; $lng = 0.0;
        foreach ($points as $p) { $lat += $p[0]; $lng += $p[1]; }
        $clat = $lat / $n; $clng = $lng / $n;
        $sum = 0.0;
        foreach ($points as $p) $sum += self::haversine($p[0], $p[1], $clat, $clng);
        return ['value' => round($sum / $n, 1), 'extra' => ['points' => $total]];
    }

    /** ¿El valor normalizado es una respuesta de «no sabe / no responde»? (heurística). */
    private static function isDontKnow(string $v): bool {
        static $set = ['dk', 'dontknow', 'dont_know', 'donotknow', 'nsnc', 'na', 'n/a',
            'refused', 'noanswer', 'no_answer', '98', '99', '-98', '-99', '999', '9999',
            'no sabe', 'nose', 'no sabe/no responde', 'no responde', 'no aplica',
            'no contesta', 'ns/nc', "don't know", 'dont know', 'do not know', 'prefer not to say'];
        return in_array(mb_strtolower(trim($v)), $set, true);
    }

    /** Primer dígito significativo (1–9) de un valor numérico, o null si no aplica. */
    private static function firstDigit($v): ?int {
        if ($v === null || is_array($v)) return null;
        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) return null;
        $s = ltrim($s, '+-');
        $s = preg_replace('/[.,\s]/', '', $s); // fuera separadores; buscamos el 1er dígito 1-9
        if ($s === null) return null;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $c = $s[$i];
            if ($c >= '1' && $c <= '9') return (int) $c;
        }
        return null; // solo ceros → sin dígito significativo
    }

    /** Distancia haversine en metros entre dos puntos [lat,lng]. */
    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * percentmatch: similitud de respuestas entre los envíos del mismo encuestador. Por
     * cada envío, su MÁXIMA coincidencia con cualquier otro (fracción de campos donde
     * AMBOS respondieron y el valor coincide); el valor del encuestador es la MEDIA de
     * esos máximos. El curbstoner rellena encuestas casi idénticas → percentmatch alto.
     *
     * Gate: ≥2 envíos con contenido. Coste O(n²·f) acotado por PM_SAMPLE.
     *
     * @return array|null ['value','extra'=>['p90','pairs_high','sample_size','sampled']] o null.
     */
    private static function percentMatch(array $vectors, int $seenTotal): ?array {
        $n = count($vectors);
        if ($n < 2) return null;

        $best = array_fill(0, $n, null);
        $pairsHigh = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                [$den, $eq] = self::pmCompare($vectors[$i], $vectors[$j]);
                if ($den === 0) continue; // sin campos comparables: par indefinido
                $m = $eq / $den;
                if ($best[$i] === null || $m > $best[$i]) $best[$i] = $m;
                if ($best[$j] === null || $m > $best[$j]) $best[$j] = $m;
                if ($m >= self::PM_PAIR_THRESHOLD) $pairsHigh++;
            }
        }
        $maxes = array_values(array_filter($best, fn($x) => $x !== null));
        if (!$maxes) return null; // ningún par comparable

        return [
            'value' => round(array_sum($maxes) / count($maxes), 4),
            'extra' => [
                'p90'         => round(self::percentile($maxes, 90), 4),
                'pairs_high'  => $pairsHigh,
                'sample_size' => $n,
                'sampled'     => $seenTotal > $n, // se retuvo una muestra del total visto
            ],
        ];
    }

    /** Compara dos vectores de respuesta: [nº campos comunes respondidos, nº que coinciden]. */
    private static function pmCompare(array $a, array $b): array {
        if (count($a) > count($b)) { $t = $a; $a = $b; $b = $t; } // iterar sobre el menor
        $den = 0; $eq = 0;
        foreach ($a as $k => $va) {
            if (!array_key_exists($k, $b)) continue;
            $den++;
            if ($va === $b[$k]) $eq++;
        }
        return [$den, $eq];
    }

    // ---------- estadística robusta ----------

    /**
     * z robusto = (valor − mediana) / (1.4826·MAD), orientado según la dirección de la
     * señal: 'high' tal cual, 'low' invertido (bajo = sospechoso), 'abs' por magnitud
     * (ambos extremos). MAD 0 → 0 (pares sin dispersión). Solo el z POSITIVO suma al
     * índice, así que ya queda «lo sospechoso» con signo positivo.
     */
    private static function zScore(float $value, array $stats, string $dir): float {
        $scale = 1.4826 * $stats['mad'];
        if ($scale <= 0.0) return 0.0;
        $z = ($value - $stats['median']) / $scale;
        if ($dir === 'abs') return abs($z);
        if ($dir === 'low') return -$z;
        return $z;
    }

    private static function level(float $z): string {
        if ($z >= self::LEVEL_HIGH) return 'high';
        if ($z >= self::LEVEL_ELEVATED) return 'elevated';
        return 'low';
    }

    /** @param float[] $vals */
    private static function median(array $vals): float {
        sort($vals);
        $c = count($vals);
        if ($c === 0) return 0.0;
        $mid = intdiv($c, 2);
        return $c % 2 ? (float) $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
    }

    /** Desviación absoluta mediana. @param float[] $vals */
    private static function mad(array $vals, float $median): float {
        if (!$vals) return 0.0;
        $dev = array_map(fn($v) => abs($v - $median), $vals);
        return self::median($dev);
    }

    /** Percentil lineal (0–100) de una lista ya sin nulos. @param float[] $vals */
    private static function percentile(array $vals, float $p): float {
        sort($vals);
        $c = count($vals);
        if ($c === 0) return 0.0;
        if ($c === 1) return (float) $vals[0];
        $rank = ($p / 100) * ($c - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        if ($lo === $hi) return (float) $vals[$lo];
        return $vals[$lo] + ($rank - $lo) * ($vals[$hi] - $vals[$lo]);
    }
}
