<?php
/**
 * POST /api/v1/forms/{id}/review   (requiere can_validate)
 * Revisión en lote: aplica un mismo estado a varios envíos del formulario.
 * Body: { uids: [submission_uid, ...], status: 'approved'|'rejected'|'on_hold'|'pending', comment?: string }
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

$form = DB::run(
    'SELECT id, deployment_status, kobo_asset_uid, kobo_account_id FROM forms WHERE id = ? AND active = 1',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'validate');
// Un formulario archivado es de solo lectura para la revisión: se ven los envíos y
// las decisiones previas, pero no se aplican nuevas (defensa en el backend; la UI
// además oculta los controles).
if (($form['deployment_status'] ?? null) === 'archived') {
    ErrorResponse::send('FORM_ARCHIVED');
}

$body    = Request::json();
$status  = $body['status'] ?? '';
$comment = isset($body['comment']) ? trim((string) $body['comment']) : null;
$uids    = $body['uids'] ?? null;

if (!in_array($status, ['approved', 'rejected', 'on_hold', 'pending'], true)) {
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

// Scoping por filas: solo se aplican los que están dentro de alcance. De cada uno
// guardamos su _id de Kobo (necesario para el push del estado de validación).
$scopeRule = RowScope::ruleForUser($user, $formId);
$targets   = []; // submission_uid => kobo _id (int)
foreach ($rows as $r) {
    $payload = json_decode($r['json_payload'], true) ?: [];
    if (!RowScope::matches($scopeRule, $payload)) {
        continue;
    }
    $koboId = $payload['_id'] ?? null;
    if (!Demo::enabled() && !$koboId) {
        // Sin _id no se puede empujar a Kobo: se omite (cuenta como skipped).
        continue;
    }
    $targets[$r['submission_uid']] = (int) $koboId;
}

// Push a Kobo (estado de validación nativo): bloqueante. Un solo PATCH para todos los
// envíos del lote (mismo estado). Si Kobo lo rechaza, NO se aplica nada localmente.
// En modo demo se omite el push (la revisión queda solo local).
$statusUid = ValidationStatus::toKobo($status);
$pushed    = false;
if (!Demo::enabled() && $targets) {
    $acc = DB::run(
        'SELECT server_url, api_token FROM kobo_accounts WHERE id = ?',
        [$form['kobo_account_id']]
    )->fetch();
    if (!$acc) {
        ErrorResponse::send('KOBO_ACCOUNT_DISABLED', 'La cuenta Kobo no existe');
    }
    $client = new KoboClient($acc['server_url'], TokenVault::decrypt($acc['api_token']));
    try {
        $client->setValidationStatuses($form['kobo_asset_uid'], array_values($targets), $statusUid);
    } catch (KoboException $e) {
        ErrorResponse::send($e->errorCode, $e->getMessage());
    }
    $pushed = true;
}

$pdo = DB::conn();
$pdo->beginTransaction();
$applied = [];
$stmt = $pdo->prepare(
    'INSERT INTO submission_reviews (submission_uid, user_id, source, status, comment) VALUES (?, ?, ?, ?, ?)'
);
$seenStmt = $pdo->prepare('UPDATE submissions_cache SET kobo_validation_seen = ? WHERE submission_uid = ?');
foreach ($targets as $sUid => $koboId) {
    $stmt->execute([$sUid, $user['id'], 'app', $status, $comment !== '' ? $comment : null]);
    if ($pushed) {
        $seenStmt->execute([$statusUid, $sUid]);
    }
    $applied[] = $sUid;
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
