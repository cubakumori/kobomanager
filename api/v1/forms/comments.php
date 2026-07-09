<?php
/**
 * GET /api/v1/forms/{id}/comments   (requiere can_view)
 * Panel de comentarios de revisión del formulario: los comentarios que ya viven en
 * submission_reviews, agrupados por equipo → encuestador (el cálculo vive en
 * lib/Comments y respeta el scoping por filas y el ocultado de columnas del usuario).
 *
 * Filtros opcionales: `?status=` (uno de los estados de revisión) y `?search=`
 * (subcadena del texto del comentario). Es solo lectura; los enlaces públicos nunca
 * lo exponen (es una vista interna, como el Control de calidad).
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run(
    'SELECT id, name, schema_json, deployment_status, stats_team_field, stats_enumerator_field
     FROM forms WHERE id = ? AND active = 1',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$scope      = RowScope::ruleForUser($user, $formId);
$fieldScope = FieldScope::ruleForUser($user, $formId);
$schemaRaw  = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;

$status = (string) ($_GET['status'] ?? '');
$status = in_array($status, ValidationStatus::STATUSES, true) ? $status : null;
$search = trim((string) ($_GET['search'] ?? ''));

$comments = Comments::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
    $form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null,
    $status, $search !== '' ? $search : null
);

ErrorResponse::ok(array_merge([
    'form'              => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status' => $form['deployment_status'] ?? null,
], $comments));
