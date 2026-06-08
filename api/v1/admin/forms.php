<?php
/**
 * GET /api/v1/admin/forms   (solo admin)
 * Lista todos los formularios en caché, con su cuenta y estado de sincronización.
 */

Auth::requireAdmin();

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$rows = DB::run(
    'SELECT f.id, f.name, f.kobo_asset_uid, f.server_url, f.deployment_status, f.last_synced_at,
            f.sync_status, f.last_sync_error, f.active, f.initial_review_status,
            a.id AS account_id, a.label AS account_label
     FROM forms f
     JOIN kobo_accounts a ON a.id = f.kobo_account_id
     ORDER BY a.label, f.name'
)->fetchAll();

foreach ($rows as &$r) {
    $r['id']         = (int) $r['id'];
    $r['account_id'] = (int) $r['account_id'];
    $r['active']     = (bool) $r['active'];
}

ErrorResponse::ok($rows);
