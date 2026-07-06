<?php
/**
 * GET /api/v1/forms/{id}/quality   (requiere can_view)
 * Control de calidad por equipo/encuestador: envíos fuera de los umbrales
 * admisibles del formulario (duración mín/máx y consecutividad mínima), con
 * drill-down a las encuestas infractoras. El cálculo vive en lib/Quality y
 * respeta el scoping por filas y el ocultado de columnas del usuario.
 *
 * Es solo lectura: el marcado en lote «en espera» lo hace el flujo de revisión
 * existente (POST forms/{id}/review). `can_validate` viaja en la respuesta para
 * que la UI sepa si ofrecer ese botón.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run(
    'SELECT id, name, schema_json, deployment_status, stats_team_field, stats_enumerator_field,
            qc_min_duration, qc_max_duration, qc_min_gap
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

$quality = Quality::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
    $form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null,
    $form['qc_min_duration'] !== null ? (int) $form['qc_min_duration'] : null,
    $form['qc_max_duration'] !== null ? (int) $form['qc_max_duration'] : null,
    $form['qc_min_gap'] !== null ? (int) $form['qc_min_gap'] : null
);

ErrorResponse::ok(array_merge([
    'form'              => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status' => $form['deployment_status'] ?? null,
    'can_validate'      => Auth::canForm($user, $formId, 'validate'),
    'can_settings'      => Auth::canForm($user, $formId, 'settings'),
], $quality));
