<?php
/**
 * GET /api/v1/forms/{id}/quality/suggest   (admin o can_settings sobre el formulario)
 *
 * Sugerencia de umbrales del control de calidad a partir de la distribución REAL
 * de duraciones del formulario (columna materializada duration_s): p5 → duración
 * menor admisible, p95 → mayor admisible, en minutos. Es solo una propuesta para
 * rellenar el formulario de ajustes; no escribe nada.
 *
 * Respeta el RowScope del usuario (mismo criterio que el resto de lecturas). Con
 * menos de MIN_SAMPLE duraciones válidas no se sugiere nada (percentiles de una
 * muestra ínfima solo generan umbrales absurdos).
 */

const QC_SUGGEST_MIN_SAMPLE = 10;

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, stats_enumerator_field FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'settings');

$scope               = RowScope::ruleForUser($user, $formId);
[$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

// Mediana de encuestas por encuestador: informa el mínimo del «Índice de riesgo»
// (por debajo del mínimo no se puntúa). Mismo campo/gating que lib/Quality: el campo
// de encuestador configurado si es visible, o el usuario Kobo que envió; los envíos
// sin valor de encuestador no forman grupo. NULL si no hay encuestadores.
$fieldScope  = FieldScope::ruleForUser($user, $formId);
$enumField   = $form['stats_enumerator_field'];
$enumIsField = $enumField !== null && $enumField !== '' && $enumField !== '_submitted_by'
    && !FieldScope::isHidden($fieldScope, $enumField);
$enumPath    = $enumIsField ? $enumField : '_submitted_by';

$perEnum = [];
foreach (DB::stream(
    "SELECT json_payload FROM submissions_cache WHERE form_id = ? AND $scopeSql",
    array_merge([$formId], $scopeP)
) as $r) {
    $ev = (json_decode($r['json_payload'], true) ?: [])[$enumPath] ?? null;
    if ($ev === null || $ev === '' || is_array($ev)) continue; // sin encuestador: no cuenta
    $key = (string) $ev;
    $perEnum[$key] = ($perEnum[$key] ?? 0) + 1;
}
$enumMedian = null;
if ($perEnum) {
    $counts = array_values($perEnum);
    sort($counts);
    $n = count($counts);
    $enumMedian = $n % 2
        ? $counts[intdiv($n, 2)]
        : (int) round(($counts[$n / 2 - 1] + $counts[$n / 2]) / 2);
}
$enumStats = ['enumerator_median' => $enumMedian, 'enumerators' => count($perEnum)];

$where  = "form_id = ? AND duration_s IS NOT NULL AND $scopeSql";
$params = array_merge([$formId], $scopeP);

$count = (int) DB::run("SELECT COUNT(*) AS c FROM submissions_cache WHERE $where", $params)->fetch()['c'];

// Percentil por índice ordenado (sin funciones de ventana: MySQL 5.7 compatible).
$pct = function (float $p) use ($where, $params, $count): int {
    $offset = (int) floor(($count - 1) * $p);
    return (int) DB::run(
        "SELECT duration_s FROM submissions_cache WHERE $where ORDER BY duration_s LIMIT 1 OFFSET $offset",
        $params
    )->fetch()['duration_s'];
};

if ($count < QC_SUGGEST_MIN_SAMPLE) {
    ErrorResponse::ok(array_merge([
        'count'     => $count,
        'min_needed' => QC_SUGGEST_MIN_SAMPLE,
        'suggested' => null,
    ], $enumStats));
}

$p5  = $pct(0.05);
$p50 = $pct(0.50);
$p95 = $pct(0.95);

ErrorResponse::ok(array_merge([
    'count'  => $count,
    'p5_s'   => $p5,
    'p50_s'  => $p50,
    'p95_s'  => $p95,
    // En MINUTOS, como las columnas qc_*: por debajo del p5 ≈ el 5 % más rápido
    // (sospechoso de apresurado); por encima del p95, el 5 % más lento.
    'suggested' => [
        'min_duration' => max(1, (int) floor($p5 / 60)),
        'max_duration' => max(1, (int) ceil($p95 / 60)),
    ],
], $enumStats));
