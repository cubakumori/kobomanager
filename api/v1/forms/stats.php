<?php
/**
 * GET /api/v1/forms/{id}/stats   (requiere can_view)
 * Estadísticas calculadas sobre submissions_cache (respetan el scoping por filas
 * y el ocultado de columnas). El cálculo vive en lib/Stats (compartido con el
 * endpoint público de enlaces compartidos); aquí solo se resuelven los permisos.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name, schema_json, deployment_status, stats_team_field, stats_enumerator_field FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$scope      = RowScope::ruleForUser($user, $formId);
$fieldScope = FieldScope::ruleForUser($user, $formId);
$schemaRaw  = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;

$stats = Stats::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'], true,
    $form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null
);

ErrorResponse::ok(array_merge([
    'form'              => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status' => $form['deployment_status'] ?? null,
], $stats));
