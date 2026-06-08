<?php
/**
 * GET /api/v1/public/share/{token}/map   (PÚBLICO, sin sesión)
 * Puntos geográficos de los envíos del enlace (con el filtro de filas aplicado).
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$link  = ShareLink::requireAccess($token, 'map');

$formId              = (int) $link['form_id'];
$schema              = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
$scope               = ShareLink::rule($link);
[$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

// Ocultado de columnas: un campo geo oculto no aporta su punto al mapa.
$fieldScope = FieldScope::ruleForLink($link);

$rows = DB::run(
    "SELECT submission_uid, json_payload, submitted_at
     FROM submissions_cache
     WHERE form_id = ? AND $scopeSql
     ORDER BY submitted_at DESC, id DESC",
    array_merge([$formId], $scopeP)
)->fetchAll();

$points = [];
foreach ($rows as $r) {
    $payload = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schema);
    $pt = Geo::primaryPoint($payload, $schema);
    if (!$pt) continue;
    $points[] = [
        'submission_uid' => $r['submission_uid'],
        'submitted_at'   => $r['submitted_at'],
        'lat'            => $pt[0],
        'lng'            => $pt[1],
    ];
}

ErrorResponse::ok([
    'form'   => ['name' => $link['form_name']],
    'points' => $points,
    'total'  => count($rows),
]);
