<?php
/**
 * GET /api/v1/forms/{id}/sample   (requiere can_view)
 *
 * Panel de cumplimiento de la MUESTRA por equipo: hecho/objetivo por celda
 * `equipo × valor` del select_one de muestreo, totales y proyección por equipo.
 * El cálculo vive en lib/Sample; aquí solo se resuelven permisos y alcance.
 *
 * Respeta el scoping por filas (un jefe de equipo ve el suyo) y el ocultado de
 * columnas. NO se cachea (igual que forms/stats.php): el alcance es por-usuario y
 * el denominador depende del estado de revisión, que cambia en la app entre syncs.
 * El enlace público de solo lectura (con su micro-caché) es una 2ª iteración.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run(
    'SELECT id, name, schema_json, deployment_status, stats_team_field,
            sample_field, sample_field2, sample_field3, sample_denominator
     FROM forms WHERE id = ? AND active = 1',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$base = [
    'form'                 => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status'    => $form['deployment_status'] ?? null,
    'team_field_configured'=> ($form['stats_team_field'] ?? '') !== '',
    'can_settings'         => Auth::canForm($user, $formId, 'settings'),
];

// Sin campo de muestreo configurado: el panel muestra el aviso de «configúralo».
if (($form['sample_field'] ?? '') === '') {
    ErrorResponse::ok($base + ['configured' => false]);
}

$scope      = RowScope::ruleForUser($user, $formId);
$fieldScope = FieldScope::ruleForUser($user, $formId);
$schemaRaw  = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$secondary  = array_values(array_filter([$form['sample_field2'], $form['sample_field3']], fn($v) => $v !== null && $v !== ''));

$sample = Sample::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
    $form['stats_team_field'] ?: null,
    (string) $form['sample_field'],
    (string) $form['sample_denominator'],
    $secondary
);

ErrorResponse::ok($base + ['configured' => true] + $sample);
