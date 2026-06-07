<?php
/**
 * GET /api/v1/admin/audit   (solo admin)
 * Visor del registro de auditoría, paginado y con filtros.
 * Query:
 *   page (1+), per_page (1-100),
 *   action  → filtra por acción exacta,
 *   user_id → filtra por usuario,
 *   form_id → filtra por formulario,
 *   date_from / date_to (YYYY-MM-DD) → rango por fecha (inclusivo),
 *   search  → texto libre sobre submission_uid o el detalle JSON.
 * Devuelve items (con nombre de usuario y formulario), total, y la lista de
 * acciones existentes (para poblar el filtro). La lógica de consulta vive en
 * Audit::query (compartida con la auditoría propia, audit/me.php).
 */

$admin = Auth::requireAdmin();

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

ErrorResponse::ok(Audit::query([
    'page'      => $_GET['page']      ?? null,
    'per_page'  => $_GET['per_page']  ?? null,
    'action'    => $_GET['action']    ?? null,
    'user_id'   => $_GET['user_id']   ?? null,
    'form_id'   => $_GET['form_id']   ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to'   => $_GET['date_to']   ?? null,
    'search'    => $_GET['search']    ?? null,
]));
