<?php
/**
 * GET /api/v1/forms/{id}/map   (requiere can_view)
 * Puntos principales de los envíos del formulario, para pintarlos en un mapa.
 * Devuelve solo los envíos que tienen una coordenada (geopoint o _geolocation).
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name, schema_json FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$schema = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;

// Scoping por filas: el viewer puede tener un filtro que limita qué envíos ve.
$scope               = RowScope::ruleForUser($user, $formId);
[$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

$rows = DB::run(
    "SELECT submission_uid, json_payload, submitted_at
     FROM submissions_cache
     WHERE form_id = ? AND $scopeSql
     ORDER BY submitted_at DESC, id DESC",
    array_merge([$formId], $scopeP)
)->fetchAll();

$points = [];
foreach ($rows as $r) {
    $payload = json_decode($r['json_payload'], true) ?: [];
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
    'form'   => ['id' => (int) $form['id'], 'name' => $form['name']],
    'points' => $points,
    'total'  => count($rows),
]);
