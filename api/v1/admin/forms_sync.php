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

// Estados de despliegue que el ajuste global indica sincronizar (p. ej. ['deployed']).
$allowedStatuses = Settings::syncStatuses();

$summary = [];

foreach ($accounts as $acc) {
    $accId = (int) $acc['id'];
    try {
        $token  = TokenVault::decrypt($acc['api_token']);
        $client = new KoboClient($acc['server_url'], $token);
        $assets = $client->getAssets();

        $count    = 0;
        $skipped  = 0;
        $seenUids = []; // todos los assets (survey) presentes en Kobo, para reconciliar bajas
        foreach ($assets as $asset) {
            $uid  = $asset['uid'] ?? null;
            $name = $asset['name'] ?? '(sin nombre)';
            if (!$uid) continue;
            $seenUids[] = $uid;

            // Estado de despliegue (deployed/draft/archived), normalizado a minúsculas.
            $status = strtolower((string) ($asset['deployment_status'] ?? ''));

            // Filtrar por el ajuste global de estados a sincronizar.
            if (!in_array($status, $allowedStatuses, true)) {
                $skipped++;
                continue;
            }

            DB::run(
                'INSERT INTO forms (kobo_account_id, kobo_asset_uid, name, server_url, deployment_status, last_synced_at, sync_status, last_sync_error)
                 VALUES (?, ?, ?, ?, ?, NOW(), \'success\', NULL)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    server_url = VALUES(server_url),
                    deployment_status = VALUES(deployment_status),
                    last_synced_at = NOW(),
                    sync_status = \'success\',
                    last_sync_error = NULL',
                [$accId, $uid, $name, rtrim($acc['server_url'], '/'), $status ?: null]
            );
            $count++;

            // Cachear/refrescar el esquema legible (labels de preguntas y opciones).
            // No interrumpe la sincronización si el contenido del asset no se puede leer.
            $formRow = DB::run(
                'SELECT id FROM forms WHERE kobo_account_id = ? AND kobo_asset_uid = ?',
                [$accId, $uid]
            )->fetch();
            if ($formRow) {
                FormSchema::fetchAndStore((int) $formRow['id'], $uid, $client);
            }
        }

        // Reconciliar bajas: borrar los formularios locales cuyo asset ya no existe en
        // Kobo (no aparece en el listado, sea cual sea su estado). Cascade limpia su caché.
        if ($seenUids) {
            $ph = implode(',', array_fill(0, count($seenUids), '?'));
            $removed = DB::run(
                "DELETE FROM forms WHERE kobo_account_id = ? AND kobo_asset_uid NOT IN ($ph)",
                array_merge([$accId], $seenUids)
            )->rowCount();
        } else {
            // Kobo no devolvió ningún formulario para esta cuenta: se borran todos.
            $removed = DB::run('DELETE FROM forms WHERE kobo_account_id = ?', [$accId])->rowCount();
        }

        Audit::log($admin['id'], 'sync_forms', null, null, ['account_id' => $accId, 'forms' => $count, 'skipped' => $skipped, 'removed' => $removed]);
        $summary[] = [
            'account_id'    => $accId,
            'account_label' => $acc['label'],
            'status'        => 'success',
            'forms'         => $count,
            'skipped'       => $skipped,
            'removed'       => $removed,
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
