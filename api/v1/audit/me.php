<?php
/**
 * GET /api/v1/audit/me   (cualquier usuario con sesión; NO requiere admin)
 * Registro de actividad PROPIO del usuario autenticado. Gobernado por el ajuste
 * global `audit_self_view_enabled` (off por defecto): si está desactivado,
 * responde 403 para todos (los admin disponen del visor completo en /admin/audit).
 *
 * Fuerza user_id = usuario actual (ignora cualquier user_id del query) y reutiliza
 * la paginación/filtros de Audit::query. No expone columna ni filtro de usuario.
 * Query: page, per_page, action, form_id, date_from, date_to, search.
 */

$user = Auth::require();

if (!Settings::auditSelfViewEnabled()) {
    ErrorResponse::send('AUTH_INSUFFICIENT_PERMISSIONS');
}

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

ErrorResponse::ok(Audit::query([
    'page'      => $_GET['page']      ?? null,
    'per_page'  => $_GET['per_page']  ?? null,
    'action'    => $_GET['action']    ?? null,
    'form_id'   => $_GET['form_id']   ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to'   => $_GET['date_to']   ?? null,
    'search'    => $_GET['search']    ?? null,
    'user_id'   => (int) $user['id'],   // FORZADO: siempre eres tú.
], true /* scopeActionsToUser */));
