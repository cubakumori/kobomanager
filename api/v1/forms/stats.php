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

// Filtro por estado de revisión: `?status=` lo fija explícitamente (al pulsar una
// tarjeta del encabezado); sin él, se aplica el alcance por defecto configurado
// globalmente ('all' o 'approved'). Cualquier valor no válido cae al por defecto.
$reqStatus = (string) ($_GET['status'] ?? '');
$filter    = in_array($reqStatus, ['all', 'pending', 'approved', 'on_hold', 'rejected'], true)
    ? $reqStatus
    : Settings::statsDefaultScope();

// Filtro por equipos (`?teams=` = claves separadas por coma; el bucket «sin equipo»
// usa '__none__'). Ausente = null = todos. Presente pero vacío = ninguno seleccionado.
$teamSel = array_key_exists('teams', $_GET)
    ? array_values(array_filter(explode(',', (string) $_GET['teams']), fn($s) => $s !== ''))
    : null;

$stats = Stats::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'], true,
    $form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null,
    $filter, $teamSel
);

ErrorResponse::ok(array_merge([
    'form'              => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status' => $form['deployment_status'] ?? null,
], $stats));
