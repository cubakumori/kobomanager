<?php
/**
 * POST /api/v1/admin/forms/sync   (solo admin)
 * Body opcional: { account_id }   → si se indica, sincroniza solo esa cuenta.
 *
 * Para cada cuenta Kobo activa: descifra el token, pide los assets (formularios)
 * y hace upsert en `forms`. Actualiza sync_status / last_sync_error y devuelve un
 * resumen por cuenta.
 */

$admin = Auth::requireAdmin();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$accountId = Request::json()['account_id'] ?? null;

$sql = 'SELECT id, label, server_url, api_token FROM kobo_accounts WHERE active = 1';
$params = [];
if ($accountId !== null) {
    $sql .= ' AND id = ?';
    $params[] = (int) $accountId;
}
$accounts = DB::run($sql, $params)->fetchAll();

$summary = [];

foreach ($accounts as $acc) {
    $accId = (int) $acc['id'];
    try {
        $token  = TokenVault::decrypt($acc['api_token']);
        $client = new KoboClient($acc['server_url'], $token);
        $assets = $client->getAssets();

        $count = 0;
        foreach ($assets as $asset) {
            $uid  = $asset['uid'] ?? null;
            $name = $asset['name'] ?? '(sin nombre)';
            if (!$uid) continue;

            DB::run(
                'INSERT INTO forms (kobo_account_id, kobo_asset_uid, name, server_url, last_synced_at, sync_status, last_sync_error)
                 VALUES (?, ?, ?, ?, NOW(), \'success\', NULL)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    server_url = VALUES(server_url),
                    last_synced_at = NOW(),
                    sync_status = \'success\',
                    last_sync_error = NULL',
                [$accId, $uid, $name, rtrim($acc['server_url'], '/')]
            );
            $count++;
        }

        Audit::log($admin['id'], 'sync_forms', null, null, ['account_id' => $accId, 'forms' => $count]);
        $summary[] = [
            'account_id'    => $accId,
            'account_label' => $acc['label'],
            'status'        => 'success',
            'forms'         => $count,
        ];
    } catch (KoboException $e) {
        // Marca como error los formularios ya conocidos de esta cuenta.
        DB::run(
            'UPDATE forms SET sync_status = \'error\', last_sync_error = ? WHERE kobo_account_id = ?',
            [$e->getMessage(), $accId]
        );
        Audit::log($admin['id'], 'sync_forms_error', null, null, ['account_id' => $accId, 'error' => $e->errorCode]);
        $summary[] = [
            'account_id'    => $accId,
            'account_label' => $acc['label'],
            'status'        => 'error',
            'error_code'    => $e->errorCode,
            'error'         => $e->getMessage(),
        ];
    }
}

ErrorResponse::ok($summary);
