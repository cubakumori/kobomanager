<?php
/**
 * GET /api/v1/admin/messages   (solo admin)
 * Bandeja de mensajes del formulario de contacto público (página «Apoyar»).
 * Query:
 *   page (1+), per_page (1-100),
 *   status → new|read|archived (vacío = todos),
 *   topic  → general|hire|proposal|using (vacío = todos).
 * Devuelve items, total (según filtros) y new_count (mensajes nuevos, global —
 * alimenta el contador de la card del Dashboard).
 */

$admin = Auth::requireAdmin();

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));

$where  = [];
$params = [];

$status = $_GET['status'] ?? '';
if (in_array($status, ['new', 'read', 'archived'], true)) {
    $where[]  = 'status = ?';
    $params[] = $status;
}
$topic = $_GET['topic'] ?? '';
if (in_array($topic, ['general', 'hire', 'proposal', 'using'], true)) {
    $where[]  = 'topic = ?';
    $params[] = $topic;
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int) DB::run("SELECT COUNT(*) FROM contact_messages $sqlWhere", $params)->fetchColumn();

$items = DB::run(
    "SELECT id, name, email, org, topic, message, emailed, status, created_at
       FROM contact_messages $sqlWhere
      ORDER BY created_at DESC, id DESC
      LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
)->fetchAll();

$newCount = (int) DB::run("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn();

ErrorResponse::ok([
    'items'     => $items,
    'total'     => $total,
    'page'      => $page,
    'per_page'  => $perPage,
    'new_count' => $newCount,
]);
