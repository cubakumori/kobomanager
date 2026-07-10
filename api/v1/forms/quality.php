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
            qc_min_duration, qc_max_duration, qc_min_gap, qc_dup_min_answers, risk_min_n
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

// Alcance por estado de revisión (ajuste global «Control de calidad: alcance»):
// por defecto solo se reportan pendientes/en espera; 'all' evalúa todos.
$qcScope  = Settings::qcScope();
$statuses = $qcScope === 'all' ? null : ['pending', 'on_hold'];

$quality = Quality::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
    $form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null,
    $form['qc_min_duration'] !== null ? (int) $form['qc_min_duration'] : null,
    $form['qc_max_duration'] !== null ? (int) $form['qc_max_duration'] : null,
    $form['qc_min_gap'] !== null ? (int) $form['qc_min_gap'] : null,
    $statuses,
    $form['qc_dup_min_answers'] !== null ? (int) $form['qc_dup_min_answers'] : null
);

// Atajo «aprobar en lote los admisibles» en la página de Control de calidad: solo se
// prepara si el ajuste global lo ofrece aquí ('qc'/'both') y el usuario puede validar.
// El botón aprueba los PENDIENTES sin bandera; si el Índice de riesgo está activo, se
// excluyen los envíos de los encuestadores de alto riesgo (Quality::admissiblePendingUids).
$canValidate = Auth::canForm($user, $formId, 'validate');
$admissible  = ['enabled' => false];
if ($canValidate && Settings::qcAdmitBatchInQc()) {
    $risk = null;
    if ($form['risk_min_n'] !== null) {
        $risk = Risk::compute(
            $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
            $form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null,
            (int) $form['risk_min_n'], $statuses
        );
    }
    $picked     = Quality::admissiblePendingUids($quality, $risk);
    $admissible = [
        'enabled'            => true,
        'uids'               => $picked['uids'],
        'count'              => count($picked['uids']),
        'high_risk_excluded' => $picked['high_risk_excluded'],
        'risk_active'        => $picked['risk_active'],
    ];
}

// `admissible_pending` es interno (materia prima con nombres); no se filtra al cliente.
unset($quality['admissible_pending']);

ErrorResponse::ok(array_merge([
    'form'              => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status' => $form['deployment_status'] ?? null,
    'can_validate'      => $canValidate,
    'can_settings'      => Auth::canForm($user, $formId, 'settings'),
    'scope'             => $qcScope,
    'admit_batch'       => Settings::qcAdmitBatch(),
    'admissible'        => $admissible,
], $quality));
