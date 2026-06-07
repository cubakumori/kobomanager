<?php
/**
 * POST /api/v1/forms/{id}/review   (requiere can_validate)
 * Revisión en lote: aplica un mismo estado a varios envíos del formulario.
 * Body: { uids: [submission_uid, ...], status: 'approved'|'rejected'|'pending', comment?: string }
 *
 * Revalida en el servidor que cada uid pertenece al formulario y está dentro del
 * alcance del scoping por filas del usuario (no se confía en el cliente). Los uids
 * fuera de alcance, de otro formulario o inexistentes se omiten (no error).
 * Devuelve { applied, skipped }.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'validate');

$body    = Request::json();
$status  = $body['status'] ?? '';
$comment = isset($body['comment']) ? trim((string) $body['comment']) : null;
$uids    = $body['uids'] ?? null;

if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Estado de revisión no válido');
}
if (!is_array($uids) || $uids === []) {
    ErrorResponse::send('VALIDATION_ERROR', 'Falta la lista de envíos (uids)');
}
// Normalizar a strings únicos; tope de seguridad por petición.
$uids = array_values(array_unique(array_map(fn($u) => (string) $u, $uids)));
if (count($uids) > 1000) {
    ErrorResponse::send('VALIDATION_ERROR', 'Demasiados envíos en una sola operación (máx. 1000)');
}

// Traer los envíos pedidos que realmente pertenecen al formulario.
$placeholders = implode(',', array_fill(0, count($uids), '?'));
$rows = DB::run(
    "SELECT submission_uid, json_payload FROM submissions_cache
     WHERE form_id = ? AND submission_uid IN ($placeholders)",
    array_merge([$formId], $uids)
)->fetchAll();

// Scoping por filas: solo se aplican los que están dentro de alcance.
$scopeRule = RowScope::ruleForUser($user, $formId);

$pdo = DB::conn();
$pdo->beginTransaction();
$applied   = [];
$stmt      = $pdo->prepare(
    'INSERT INTO submission_reviews (submission_uid, user_id, status, comment) VALUES (?, ?, ?, ?)'
);
foreach ($rows as $r) {
    if (!RowScope::matches($scopeRule, json_decode($r['json_payload'], true) ?: [])) {
        continue;
    }
    $stmt->execute([$r['submission_uid'], $user['id'], $status, $comment !== '' ? $comment : null]);
    $applied[] = $r['submission_uid'];
}
$pdo->commit();

$skipped = count($uids) - count($applied);

Audit::log($user['id'], 'review_batch', $formId, null, [
    'status'  => $status,
    'comment' => $comment,
    'applied' => count($applied),
    'skipped' => $skipped,
    'uids'    => $applied,
]);

ErrorResponse::ok([
    'status'  => $status,
    'applied' => count($applied),
    'skipped' => $skipped,
]);
