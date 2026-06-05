<?php
/**
 * GET /api/v1/forms/{id}/submissions   (requiere can_view)
 * Lista paginada de envíos desde submissions_cache.
 * Query: page (1+), per_page (1-100), search (texto libre sobre el JSON).
 * Cada envío incluye su estado de revisión más reciente.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name, schema_json FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;
$search  = trim((string) ($_GET['search'] ?? ''));

$where  = 'WHERE form_id = ?';
$params = [$formId];
if ($search !== '') {
    $where    .= ' AND CAST(json_payload AS CHAR) LIKE ?';
    $params[]  = '%' . $search . '%';
}

$total = (int) DB::run("SELECT COUNT(*) AS c FROM submissions_cache $where", $params)
    ->fetch()['c'];

$rows = DB::run(
    "SELECT id, submission_uid, json_payload, submitted_at
     FROM submissions_cache
     $where
     ORDER BY submitted_at DESC, id DESC
     LIMIT $perPage OFFSET $offset",
    $params
)->fetchAll();

// Estado de revisión más reciente para los envíos de esta página.
$uids = array_column($rows, 'submission_uid');
$reviewByUid = [];
if ($uids) {
    $in = implode(',', array_fill(0, count($uids), '?'));
    $reviews = DB::run(
        "SELECT r.submission_uid, r.status
         FROM submission_reviews r
         JOIN (
            SELECT submission_uid, MAX(id) AS max_id
            FROM submission_reviews
            WHERE submission_uid IN ($in)
            GROUP BY submission_uid
         ) latest ON latest.max_id = r.id",
        $uids
    )->fetchAll();
    foreach ($reviews as $rv) {
        $reviewByUid[$rv['submission_uid']] = $rv['status'];
    }
}

$items = array_map(function ($r) use ($reviewByUid) {
    return [
        'id'             => (int) $r['id'],
        'submission_uid' => $r['submission_uid'],
        'submitted_at'   => $r['submitted_at'],
        'review_status'  => $reviewByUid[$r['submission_uid']] ?? 'pending',
        'data'           => json_decode($r['json_payload'], true),
    ];
}, $rows);

// Etiquetas legibles: esquema del formulario resuelto al idioma del usuario.
$schema = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;

ErrorResponse::ok([
    'form'       => ['id' => (int) $form['id'], 'name' => $form['name']],
    'items'      => $items,
    'page'       => $page,
    'per_page'   => $perPage,
    'total'      => $total,
    'label_mode' => Settings::labelMode(),
    'schema'     => FormSchema::resolve($schema, $user['locale']),
]);
