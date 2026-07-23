<?php
/**
 * GET /api/v1/forms/{id}/risk   (requiere can_view)
 * Índice de riesgo por equipo/encuestador: detección heurística de fabricación
 * («curbstoning»), con desglose de componentes por encuestador y señales de equipo.
 * El cálculo vive en lib/Risk y respeta el scoping por filas y el ocultado de columnas.
 *
 * OPT-IN: solo puntúa si el formulario define `forms.risk_min_n` (N mínimo por
 * encuestador/equipo). Con `risk_min_n` NULL la respuesta viaja `enabled: false` y la
 * UI muestra el estado vacío que invita a configurarlo. Es una señal para PRIORIZAR
 * verificaciones (back-checks), NUNCA una prueba de fraude.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run(
    'SELECT id, name, schema_json, deployment_status, stats_team_field, stats_enumerator_field, risk_min_n
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

// Alcance por estado de revisión: reutiliza el ajuste global del control de calidad
// (por defecto solo pendientes/en espera; 'all' evalúa todos). Igual que quality.php,
// `?scope=` lo sustituye SOLO para esta petición (toggle transitorio de la vista,
// disponible para cualquiera con can_view); un valor no reconocido cae al global.
$qcScope = Settings::qcScope();
$scopeParam = (string) ($_GET['scope'] ?? '');
if (in_array($scopeParam, Settings::VALID_QC_SCOPE, true)) {
    $qcScope = $scopeParam;
}
$statuses = $qcScope === 'all' ? null : ['pending', 'on_hold'];

$risk = Risk::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
    $form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null,
    $form['risk_min_n'] !== null ? (int) $form['risk_min_n'] : null,
    $statuses
);

ErrorResponse::ok(array_merge([
    'form'              => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status' => $form['deployment_status'] ?? null,
    'can_settings'      => Auth::canForm($user, $formId, 'settings'),
    'scope'             => $qcScope,
], $risk));
