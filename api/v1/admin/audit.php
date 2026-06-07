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
 * acciones existentes (para poblar el filtro).
 */

$admin = Auth::requireAdmin();

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

$action = trim((string) ($_GET['action'] ?? ''));
if ($action !== '') {
    $where[]  = 'a.action = ?';
    $params[] = $action;
}
$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId) {
    $where[]  = 'a.user_id = ?';
    $params[] = $userId;
}
$formId = (int) ($_GET['form_id'] ?? 0);
if ($formId) {
    $where[]  = 'a.form_id = ?';
    $params[] = $formId;
}
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
if ($dateFrom !== '' && ($ts = strtotime($dateFrom)) !== false) {
    $where[]  = 'a.created_at >= ?';
    $params[] = date('Y-m-d 00:00:00', $ts);
}
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if ($dateTo !== '' && ($ts = strtotime($dateTo)) !== false) {
    $where[]  = 'a.created_at < ?';
    $params[] = date('Y-m-d 00:00:00', strtotime('+1 day', $ts));
}
$search = trim((string) ($_GET['search'] ?? ''));
if ($search !== '') {
    $where[]  = '(a.submission_uid LIKE ? OR CAST(a.detail AS CHAR) LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int) DB::run("SELECT COUNT(*) AS c FROM audit_log a $whereSql", $params)->fetch()['c'];

$rows = DB::run(
    "SELECT a.id, a.user_id, a.form_id, a.submission_uid, a.action, a.detail, a.created_at,
            u.name AS user_name, u.email AS user_email, f.name AS form_name
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     LEFT JOIN forms f ON f.id = a.form_id
     $whereSql
     ORDER BY a.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
)->fetchAll();

$items = array_map(fn($r) => [
    'id'             => (int) $r['id'],
    'created_at'     => $r['created_at'],
    'action'         => $r['action'],
    'user_id'        => $r['user_id'] !== null ? (int) $r['user_id'] : null,
    'user_name'      => $r['user_name'],
    'user_email'     => $r['user_email'],
    'form_id'        => $r['form_id'] !== null ? (int) $r['form_id'] : null,
    'form_name'      => $r['form_name'],
    'submission_uid' => $r['submission_uid'],
    'detail'         => $r['detail'] !== null ? json_decode($r['detail'], true) : null,
], $rows);

// Acciones existentes (para el desplegable de filtro).
$actions = array_map(
    fn($r) => $r['action'],
    DB::run('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll()
);

ErrorResponse::ok([
    'items'    => $items,
    'page'     => $page,
    'per_page' => $perPage,
    'total'    => $total,
    'actions'  => $actions,
]);
