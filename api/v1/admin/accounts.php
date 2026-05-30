<?php
/**
 * /api/v1/admin/accounts   (solo admin)
 *   GET  → lista de cuentas Kobo (SIN el token)
 *   POST → crea cuenta { label, server_url, email, api_token }
 *          El token se cifra con TokenVault antes de guardarse y nunca se devuelve.
 */

$admin = Auth::requireAdmin();

if (Request::method() === 'GET') {
    $rows = DB::run(
        'SELECT id, label, server_url, email, active, created_at FROM kobo_accounts ORDER BY created_at DESC'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id']     = (int) $r['id'];
        $r['active'] = (bool) $r['active'];
    }
    ErrorResponse::ok($rows);
}

if (Request::method() === 'POST') {
    $in = Request::required(['label', 'server_url', 'email', 'api_token']);

    if (!filter_var($in['server_url'], FILTER_VALIDATE_URL)) {
        ErrorResponse::send('VALIDATION_ERROR', 'server_url no es una URL válida');
    }
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Email no válido');
    }

    // Normaliza la URL del servidor (sin barra final).
    $serverUrl = rtrim($in['server_url'], '/');

    DB::run(
        'INSERT INTO kobo_accounts (label, server_url, email, api_token) VALUES (?, ?, ?, ?)',
        [$in['label'], $serverUrl, $in['email'], TokenVault::encrypt($in['api_token'])]
    );
    $id = (int) DB::conn()->lastInsertId();

    Audit::log($admin['id'], 'create_kobo_account', null, null, ['account_id' => $id, 'label' => $in['label']]);

    ErrorResponse::ok([
        'id'         => $id,
        'label'      => $in['label'],
        'server_url' => $serverUrl,
        'email'      => $in['email'],
        'active'     => true,
    ], 201);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
