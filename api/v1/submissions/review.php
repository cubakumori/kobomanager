<?php
/**
 * POST /api/v1/submissions/{id}/review   ({id} = submission_uid; requiere can_validate)
 * Body: { status: 'approved'|'rejected'|'pending', comment?: string }
 * Crea una revisión interna en submission_reviews y la audita.
 */

$user = Auth::require();
$uid  = (string) Request::param('id');

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$sub = DB::run(
    'SELECT sc.submission_uid, sc.form_id FROM submissions_cache sc WHERE sc.submission_uid = ?',
    [$uid]
)->fetch();
if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}
$formId = (int) $sub['form_id'];
Auth::requireForm($user, $formId, 'validate');

$body    = Request::json();
$status  = $body['status'] ?? '';
$comment = isset($body['comment']) ? trim((string) $body['comment']) : null;

if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Estado de revisión no válido');
}

DB::run(
    'INSERT INTO submission_reviews (submission_uid, user_id, status, comment) VALUES (?, ?, ?, ?)',
    [$uid, $user['id'], $status, $comment !== '' ? $comment : null]
);
$reviewId = (int) DB::conn()->lastInsertId();

Audit::log($user['id'], $status, $formId, $uid, ['comment' => $comment]);

ErrorResponse::ok([
    'id'            => $reviewId,
    'review_status' => $status,
    'comment'       => $comment,
], 201);
