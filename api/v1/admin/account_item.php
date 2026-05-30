<?php
/**
 * /api/v1/admin/accounts/{id}   (solo admin)
 *   PUT    → edita la cuenta { label, server_url, email, api_token? }.
 *            El token solo se re-cifra si se envía uno nuevo (no vacío).
 *   DELETE → elimina la cuenta SOLO si no tiene formularios sincronizados
 *            (el borrado haría cascade sobre formularios y envíos).
 */

$admin = Auth::requireAdmin();
$id    = (int) Request::param('id');

$acc = DB::run('SELECT id, label FROM kobo_accounts WHERE id = ?', [$id])->fetch();
if (!$acc) {
    ErrorResponse::send('NOT_FOUND', 'Cuenta no encontrada');
}

$formCount = (int) DB::run(
    'SELECT COUNT(*) AS c FROM forms WHERE kobo_account_id = ?',
    [$id]
)->fetch()['c'];

if (Request::method() === 'PUT') {
    $in = Request::required(['label', 'server_url', 'email']);
    if (!filter_var($in['server_url'], FILTER_VALIDATE_URL)) {
        ErrorResponse::send('VALIDATION_ERROR', 'server_url no es una URL válida');
    }
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Email no válido');
    }
    $serverUrl = rtrim($in['server_url'], '/');

    // El token es opcional: solo se actualiza si llega uno nuevo y no vacío.
    $newToken = Request::json()['api_token'] ?? '';
    $newToken = is_string($newToken) ? trim($newToken) : '';

    if ($newToken !== '') {
        DB::run(
            'UPDATE kobo_accounts SET label = ?, server_url = ?, email = ?, api_token = ? WHERE id = ?',
            [$in['label'], $serverUrl, $in['email'], TokenVault::encrypt($newToken), $id]
        );
    } else {
        DB::run(
            'UPDATE kobo_accounts SET label = ?, server_url = ?, email = ? WHERE id = ?',
            [$in['label'], $serverUrl, $in['email'], $id]
        );
    }

    Audit::log($admin['id'], 'edit_kobo_account', null, null, [
        'account_id'    => $id,
        'token_changed' => $newToken !== '',
    ]);

    ErrorResponse::ok([
        'id'         => $id,
        'label'      => $in['label'],
        'server_url' => $serverUrl,
        'email'      => $in['email'],
    ]);
}

if (Request::method() === 'DELETE') {
    if ($formCount > 0) {
        ErrorResponse::send(
            'VALIDATION_ERROR',
            'No se puede eliminar: la cuenta tiene formularios sincronizados. Elimínalos primero.'
        );
    }
    DB::run('DELETE FROM kobo_accounts WHERE id = ?', [$id]);
    Audit::log($admin['id'], 'delete_kobo_account', null, null, ['account_id' => $id, 'label' => $acc['label']]);
    ErrorResponse::ok(['deleted' => true]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
