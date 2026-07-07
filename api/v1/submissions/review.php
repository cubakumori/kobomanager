<?php
/**
 * POST /api/v1/submissions/{id}/review   ({id} = submission_uid; requiere can_validate)
 * Body: { status: 'approved'|'rejected'|'on_hold'|'pending', comment?: string }
 * Crea una revisión interna en submission_reviews y la audita.
 */

$user = Auth::require();
$uid  = (string) Request::param('id');

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$sub = DB::run(
    'SELECT sc.submission_uid, sc.form_id, sc.json_payload,
            f.deployment_status, f.kobo_asset_uid, f.kobo_account_id
     FROM submissions_cache sc JOIN forms f ON f.id = sc.form_id
     WHERE sc.submission_uid = ?',
    [$uid]
)->fetch();
if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}
$formId = (int) $sub['form_id'];
Auth::requireForm($user, $formId, 'validate');
// Archivado = solo lectura para la revisión (ver review_batch.php).
if (($sub['deployment_status'] ?? null) === 'archived') {
    ErrorResponse::send('FORM_ARCHIVED');
}

// Scoping por filas: un envío fuera de alcance se comporta como inexistente (404).
$scopeRule = RowScope::ruleForUser($user, $formId);
if (!RowScope::matches($scopeRule, json_decode($sub['json_payload'], true) ?: [])) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}

$body    = Request::json();
$status  = $body['status'] ?? '';
$comment = isset($body['comment']) ? trim((string) $body['comment']) : null;

if (!in_array($status, ['approved', 'rejected', 'on_hold', 'pending'], true)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Estado de revisión no válido');
}

// Carrera revisión↔sync: el pull de validación del sync hace un merge a 3 vías
// (baseline + última revisión); si se cuela entre el push a Kobo y la actualización
// de la línea base, insertaría una revisión sintética duplicada en el historial.
// Mismo lock por formulario que el sync, con espera acotada; si expira se continúa
// (el riesgo residual es solo cosmético, nunca bloquea al usuario). En las salidas
// por error el lock se libera con la conexión al terminar la petición.
$lockName = 'km.sync.form.' . $formId;
$gotLock  = ((int) DB::run('SELECT GET_LOCK(?, 5) AS l', [$lockName])->fetch()['l']) === 1;

// Push a Kobo (estado de validación nativo): bloqueante, como la edición. Si Kobo lo
// rechaza, NO se guarda la revisión local (ambos lados quedan idénticos). En modo demo
// se omite el push (no se escribe en la cuenta Kobo real); la revisión queda solo local.
$statusUid = ValidationStatus::toKobo($status);
$pushed    = false;
if (!Demo::enabled()) {
    $payload = json_decode($sub['json_payload'], true) ?: [];
    $koboId  = $payload['_id'] ?? null;
    if (!$koboId) {
        ErrorResponse::send('INTERNAL_ERROR', 'El envío en caché no tiene _id de Kobo');
    }
    $acc = DB::run(
        'SELECT server_url, api_token FROM kobo_accounts WHERE id = ?',
        [$sub['kobo_account_id']]
    )->fetch();
    if (!$acc) {
        ErrorResponse::send('KOBO_ACCOUNT_DISABLED', 'La cuenta Kobo no existe');
    }
    $client = new KoboClient($acc['server_url'], TokenVault::decrypt($acc['api_token']));
    try {
        $client->setValidationStatuses($sub['kobo_asset_uid'], [(int) $koboId], $statusUid);
    } catch (KoboException $e) {
        ErrorResponse::send($e->errorCode, $e->getMessage());
    }
    $pushed = true;
}

// recordReview inserta la fila del log Y actualiza el estado vigente
// desnormalizado (submissions_cache.review_status) de una vez.
$reviewId = ValidationStatus::recordReview($uid, (int) $user['id'], 'app', $status, $comment !== '' ? $comment : null);

// Línea base del merge a 3 vías: solo si de verdad empujamos a Kobo (en demo no se
// toca, para que el pull no malinterprete el estado real de la cuenta).
if ($pushed) {
    DB::run(
        'UPDATE submissions_cache SET kobo_validation_seen = ? WHERE submission_uid = ?',
        [$statusUid, $uid]
    );
}

if ($gotLock) {
    DB::run('SELECT RELEASE_LOCK(?)', [$lockName]);
}

Audit::log($user['id'], $status, $formId, $uid, ['comment' => $comment]);

ErrorResponse::ok([
    'id'            => $reviewId,
    'review_status' => $status,
    'comment'       => $comment,
], 201);
