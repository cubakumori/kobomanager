<?php
/**
 * GET /api/v1/submissions/{id}   (requiere can_view sobre el formulario del envío)
 * {id} es el submission_uid. Devuelve el envío completo desde caché, su formulario
 * y el historial de revisiones. Registra la visualización en audit_log.
 */

$user = Auth::require();
$uid  = (string) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$sub = DB::run(
    'SELECT sc.id, sc.submission_uid, sc.json_payload, sc.submitted_at, sc.last_synced_at,
            f.id AS form_id, f.name AS form_name
     FROM submissions_cache sc
     JOIN forms f ON f.id = sc.form_id
     WHERE sc.submission_uid = ?',
    [$uid]
)->fetch();

if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}
$formId = (int) $sub['form_id'];
Auth::requireForm($user, $formId, 'view');

$reviews = DB::run(
    'SELECT r.id, r.status, r.comment, r.created_at, u.name AS user_name
     FROM submission_reviews r
     JOIN users u ON u.id = r.user_id
     WHERE r.submission_uid = ?
     ORDER BY r.id DESC',
    [$uid]
)->fetchAll();

Audit::log($user['id'], 'view', $formId, $uid);

ErrorResponse::ok([
    'submission_uid' => $sub['submission_uid'],
    'form'           => ['id' => $formId, 'name' => $sub['form_name']],
    'submitted_at'   => $sub['submitted_at'],
    'last_synced_at' => $sub['last_synced_at'],
    'data'           => json_decode($sub['json_payload'], true),
    'review_status'  => $reviews[0]['status'] ?? 'pending',
    'reviews'        => $reviews,
]);
