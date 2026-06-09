<?php
/**
 * GET /api/v1/forms/{id}/stats   (requiere can_view)
 * Estadísticas calculadas sobre submissions_cache (respetan el scoping por filas):
 *   - total de envíos, frescura (último envío)
 *   - envíos por día (fecha de envío)
 *   - distribución por estado de revisión (última revisión de cada envío)
 *   - distribución por pregunta de opción (select_one y select_multiple), resuelta
 *     al idioma del usuario y respetando el modo de etiquetas
 *   - por enumerador (_submitted_by)
 *   - duración (media/mediana + histograma), actividad por hora/día, adjuntos
 *     (% y por tipo) y cobertura geográfica — todo vía lib/Derived
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name, schema_json, deployment_status FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

// Scoping por filas: el viewer puede tener un filtro que limita qué envíos cuenta.
$scope               = RowScope::ruleForUser($user, $formId);
[$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

// Permisos por columna: campos ocultos que no deben contar ni mostrarse.
$fieldScope = FieldScope::ruleForUser($user, $formId);

$total = (int) DB::run(
    "SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ? AND $scopeSql",
    array_merge([$formId], $scopeP)
)->fetch()['c'];

// Envíos por día.
$byDay = DB::run(
    "SELECT DATE(submitted_at) AS day, COUNT(*) AS count
     FROM submissions_cache
     WHERE form_id = ? AND submitted_at IS NOT NULL AND $scopeSql
     GROUP BY DATE(submitted_at)
     ORDER BY day",
    array_merge([$formId], $scopeP)
)->fetchAll();
$byDay = array_map(fn($r) => ['date' => $r['day'], 'count' => (int) $r['count']], $byDay);

// Si el tramo (primer→último envío) supera 30 días, el gráfico se agrupa por MES
// (YYYY-MM) en vez de por día, para que no se vuelva ilegible en periodos largos.
// by_day viene ordenado ascendente, así que el primero/último marcan el tramo.
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

// Total ACUMULADO a lo largo de la serie temporal (para la línea acumulada del gráfico).
$run = 0;
foreach ($byDay as &$d) { $run += $d['count']; $d['cumulative'] = $run; }
unset($d);
$run = 0;
foreach ($byMonth as &$m) { $run += $m['count']; $m['cumulative'] = $run; }
unset($m);

// Tendencia reciente: envíos de los últimos 7/30 días vs el periodo ANTERIOR equivalente
// (relativo a ahora; respeta el scope por filas). pct = variación %, null si el periodo
// anterior fue 0 (no se puede calcular variación → la UI muestra «—»).
$tr = DB::run(
    "SELECT
        SUM(submitted_at >= NOW() - INTERVAL 7 DAY)                                          AS last7,
        SUM(submitted_at <  NOW() - INTERVAL 7 DAY  AND submitted_at >= NOW() - INTERVAL 14 DAY) AS prev7,
        SUM(submitted_at >= NOW() - INTERVAL 30 DAY)                                         AS last30,
        SUM(submitted_at <  NOW() - INTERVAL 30 DAY AND submitted_at >= NOW() - INTERVAL 60 DAY) AS prev30
     FROM submissions_cache
     WHERE form_id = ? AND submitted_at IS NOT NULL AND $scopeSql",
    array_merge([$formId], $scopeP)
)->fetch();
$pct = fn(int $cur, int $prev): ?float => $prev > 0 ? round(($cur - $prev) * 100 / $prev, 1) : null;
$last7 = (int) ($tr['last7'] ?? 0); $prev7 = (int) ($tr['prev7'] ?? 0);
$last30 = (int) ($tr['last30'] ?? 0); $prev30 = (int) ($tr['prev30'] ?? 0);
$trend = [
    'last_7' => $last7, 'prev_7' => $prev7, 'pct_7' => $pct($last7, $prev7),
    'last_30' => $last30, 'prev_30' => $prev30, 'pct_30' => $pct($last30, $prev30),
];

// Distribución por estado de revisión: la revisión más reciente de cada envío de este formulario.
$reviewed = DB::run(
    "SELECT r.status, COUNT(*) AS count
     FROM submission_reviews r
     JOIN (
        SELECT submission_uid, MAX(id) AS max_id FROM submission_reviews GROUP BY submission_uid
     ) latest ON latest.max_id = r.id
     JOIN submissions_cache sc ON sc.submission_uid = r.submission_uid AND sc.form_id = ?
        AND $scopeSql
     GROUP BY r.status",
    array_merge([$formId], $scopeP)
)->fetchAll();

$byStatus = ['pending' => 0, 'approved' => 0, 'on_hold' => 0, 'rejected' => 0];
$reviewedTotal = 0;
foreach ($reviewed as $r) {
    if (isset($byStatus[$r['status']])) {
        $byStatus[$r['status']] = (int) $r['count'];
        $reviewedTotal += (int) $r['count'];
    }
}
// Los envíos sin revisión cuentan como 'pending'.
$byStatus['pending'] += $total - $reviewedTotal;

// ---------------------------------------------------------------------------
// Métricas enriquecidas: una sola pasada en PHP sobre los payloads en alcance.
// Reutiliza FormSchema (etiquetas/opciones), Settings (modo etiquetas) y Derived
// (duración, adjuntos, geo, hora/día) — lo mismo que el detalle/tabla/CSV.
// ---------------------------------------------------------------------------
$schemaRaw = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$resolved  = FormSchema::resolve($schemaRaw, $user['locale']);
$labelsOn  = Settings::labelMode() === 'labels';
$labels    = $resolved['labels'] ?? [];
$options   = $resolved['options'] ?? [];

// Preguntas de opción (select_one y select_multiple): se cuentan por opción, en el
// orden del esquema (así no hay huecos en la numeración). En select_multiple un
// envío puede marcar varias opciones (valor = códigos separados por espacios);
// `answered` cuenta encuestados con ≥1 selección y los % son sobre encuestados,
// por lo que pueden sumar más de 100 % (se marca `multi` para que la UI lo indique).
$singleFields = [];
foreach (($schemaRaw['fields'] ?? []) as $path => $fd) {
    if (FieldScope::isHidden($fieldScope, (string) $path)) continue; // pregunta oculta → no se cuenta
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

$rows = DB::run(
    "SELECT json_payload, submitted_at FROM submissions_cache WHERE form_id = ? AND $scopeSql",
    array_merge([$formId], $scopeP)
)->fetchAll();

foreach ($rows as $r) {
    // Recorta los campos ocultos: adjuntos/geo de un campo oculto no cuentan.
    $payload = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schemaRaw);

    if ($r['submitted_at'] !== null && ($lastSub === null || $r['submitted_at'] > $lastSub)) {
        $lastSub = $r['submitted_at'];
    }

    // Distribución por pregunta (select_one y select_multiple).
    foreach ($singleFields as $path => $f) {
        $v = $payload[$path] ?? null;
        if ($v === null || $v === '' || is_array($v)) continue;
        if ($f['multi']) {
            // select_multiple: códigos separados por espacios; cuenta cada opción única.
            $codes = preg_split('/\s+/', trim((string) $v), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!$codes) continue;
            foreach (array_unique($codes) as $code) {
                $qCounts[$path][$code] = ($qCounts[$path][$code] ?? 0) + 1;
            }
            $qAnswered[$path] = ($qAnswered[$path] ?? 0) + 1; // encuestados con ≥1 selección
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

    // Métricas derivadas (duración, adjuntos, geo, hora/día).
    $d = Derived::compute($payload, $schemaRaw, $r['submitted_at']);
    if ($d['duration_s'] !== null) $durations[] = $d['duration_s'];
    if ($d['submitted_hour'] !== null) $byHour[$d['submitted_hour']]++;
    if ($d['submitted_dow'] !== null) $byDow[$d['submitted_dow']]++;
    if ($d['has_attachments']) $attWith++;
    foreach ($attByKind as $k => $_) $attByKind[$k] += $d['attachments_by_kind'][$k] ?? 0;
    if ($d['has_geo']) $geoWith++;
}

// --- Ensamblar «por pregunta»: etiquetas resueltas, ordenado desc, top 20 + otros. ---
$OPT_CAP = 20;
$byQuestion = [];
foreach ($singleFields as $path => $f) {
    $counts = $qCounts[$path] ?? [];
    if (!$counts) continue; // sin respuestas → no se muestra
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
        'multi'    => $f['multi'], // select_multiple: % sobre encuestados (pueden sumar >100 %)
        'options'  => $opts,
        'others'   => $othersCount, // suma de opciones más allá del top 20
    ];
}

// --- Por enumerador: ordenado desc, top 20 + otros. ---
arsort($enumCounts);
$byEnumerator = [];
$enumOthers = 0;
$rank = 0;
foreach ($enumCounts as $name => $c) {
    if ($rank++ < 20) {
        $byEnumerator[] = ['name' => $name, 'count' => $c, 'pct' => $total > 0 ? round($c * 100 / $total, 1) : 0];
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
    // Cubetas (cota superior en segundos => clave i18n).
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

ErrorResponse::ok([
    'form'            => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status' => $form['deployment_status'] ?? null,
    'total'           => $total,
    'last_submission' => $lastSub,
    'by_day'          => $byDay,
    'by_month'        => $byMonth,
    'period_granularity' => $periodGranularity, // 'day' | 'month' (>30 días de tramo)
    'trend'           => $trend,
    'by_status'       => $byStatus,
    'by_question'     => $byQuestion,
    'by_enumerator'   => $byEnumerator,
    'enumerator_others' => $enumOthers,
    'duration'        => $duration,
    'by_hour'         => $byHour,
    'by_dow'          => $byDow,
    'attachments'     => [
        'with'    => $attWith,
        'without' => $total - $attWith,
        'with_pct'=> $total > 0 ? round($attWith * 100 / $total, 1) : 0,
        'by_kind' => $attByKind,
    ],
    'geo'             => [
        'with'    => $geoWith,
        'without' => $total - $geoWith,
        'with_pct'=> $total > 0 ? round($geoWith * 100 / $total, 1) : 0,
    ],
    'label_mode'      => Settings::labelMode(),
]);
