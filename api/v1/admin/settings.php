<?php
/**
 * /api/v1/admin/settings   (solo admin)
 *   GET → ajustes globales actuales.
 *   PUT → { sync_deployment_statuses: ["deployed","draft","archived"] }
 */

$admin = Auth::requireAdmin();

if (Request::method() === 'GET') {
    ErrorResponse::ok([
        'sync_deployment_statuses' => Settings::syncStatuses(),
        'valid_statuses'           => Settings::VALID_STATUSES,
    ]);
}

if (Request::method() === 'PUT') {
    $in = Request::json()['sync_deployment_statuses'] ?? null;
    if (!is_array($in)) {
        ErrorResponse::send('VALIDATION_ERROR', 'sync_deployment_statuses debe ser una lista');
    }
    $clean = array_values(array_intersect(
        array_map('strtolower', array_map('strval', $in)),
        Settings::VALID_STATUSES
    ));
    if (!$clean) {
        ErrorResponse::send('VALIDATION_ERROR', 'Debe seleccionarse al menos un estado válido');
    }
    Settings::set('sync_deployment_statuses', $clean);
    Audit::log($admin['id'], 'update_settings', null, null, ['sync_deployment_statuses' => $clean]);
    ErrorResponse::ok(['sync_deployment_statuses' => $clean]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
