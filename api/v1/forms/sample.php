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
 *
 * `?denominator=approved|approved_pending` sustituye `forms.sample_denominator`
 * SOLO para esta petición (toggle transitorio de la vista, espejo del `?scope=`
 * del control de calidad): nada se escribe ni pide permiso extra; la respuesta
 * lleva siempre el denominador EFECTIVO aplicado.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run(
    'SELECT id, name, schema_json, deployment_status, stats_team_field, team_group_field,
            sample_field, sample_field2, sample_field3, sample_denominator, retention_days
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
    // Para el enlace «Configurar la muestra» del panel: editar el plan pide el
    // permiso jerárquico «Muestra», no basta «Ajustes».
    'can_sample'           => Auth::canForm($user, $formId, 'sample'),
    // Retención configurada (días o null): con retención, el panel avisa de que
    // solo cuenta la ventana retenida (lo purgado descuenta la cuota).
    'retention_days'       => $form['retention_days'] !== null ? (int) $form['retention_days'] : null,
];

// Sin campo de muestreo configurado: el panel muestra el aviso de «configúralo».
if (($form['sample_field'] ?? '') === '') {
    ErrorResponse::ok($base + ['configured' => false]);
}

$scope      = RowScope::ruleForUser($user, $formId);
$fieldScope = FieldScope::ruleForUser($user, $formId);
$schemaRaw  = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$secondary  = array_values(array_filter([$form['sample_field2'], $form['sample_field3']], fn($v) => $v !== null && $v !== ''));

// Denominador efectivo: el del plan, salvo override transitorio válido en `?denominator=`.
$denominator = (string) $form['sample_denominator'];
$denomParam  = (string) ($_GET['denominator'] ?? '');
if (in_array($denomParam, ['approved', 'approved_pending'], true)) {
    $denominator = $denomParam;
}

$sample = Sample::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
    $form['stats_team_field'] ?: null,
    (string) $form['sample_field'],
    $denominator,
    $secondary,
    null, null,
    $form['team_group_field'] ?: null
);

ErrorResponse::ok($base + ['configured' => true] + $sample);
