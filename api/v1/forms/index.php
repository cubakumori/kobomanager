<?php
/**
 * GET /api/v1/forms
 * Formularios visibles para el usuario autenticado.
 *  - admin: todos los formularios activos (con capacidades totales).
 *  - viewer: solo aquellos con can_view = 1.
 * Incluye el número de envíos en caché.
 */

$user = Auth::require();

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

if ($user['role'] === 'admin') {
    $rows = DB::run(
        'SELECT f.id, f.name, a.label AS account_label, f.last_synced_at, f.sync_status,
                f.submissions_synced_at, f.server_url, f.kobo_asset_uid, f.deployment_status,
                1 AS can_edit, 1 AS can_validate,
                (SELECT COUNT(*) FROM submissions_cache sc WHERE sc.form_id = f.id) AS submission_count
         FROM forms f
         JOIN kobo_accounts a ON a.id = f.kobo_account_id
         WHERE f.active = 1
         ORDER BY a.label, f.name'
    )->fetchAll();
} else {
    $rows = DB::run(
        'SELECT f.id, f.name, a.label AS account_label, f.last_synced_at, f.sync_status,
                f.submissions_synced_at, f.server_url, f.kobo_asset_uid, f.deployment_status,
                p.can_edit, p.can_validate, p.row_filter,
                (SELECT COUNT(*) FROM submissions_cache sc WHERE sc.form_id = f.id) AS submission_count
         FROM forms f
         JOIN kobo_accounts a ON a.id = f.kobo_account_id
         JOIN user_form_permissions p ON p.form_id = f.id AND p.user_id = ? AND p.can_view = 1
         WHERE f.active = 1
         ORDER BY a.label, f.name',
        [$user['id']]
    )->fetchAll();

    // Conteo en alcance: solo recalcula los formularios con filtro por filas.
    foreach ($rows as &$rf) {
        $scope = RowScope::normalize(
            $rf['row_filter'] ? json_decode($rf['row_filter'], true) : null
        );
        if ($scope !== null) {
            [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');
            $rf['submission_count'] = (int) DB::run(
                "SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ? AND $scopeSql",
                array_merge([$rf['id']], $scopeP)
            )->fetch()['c'];
        }
        unset($rf['row_filter']);
    }
    unset($rf);
}

foreach ($rows as &$r) {
    $r['id']               = (int) $r['id'];
    $r['can_edit']         = (bool) $r['can_edit'];
    $r['can_validate']     = (bool) $r['can_validate'];
    $r['submission_count'] = (int) $r['submission_count'];
    // ¿Se han sincronizado ya los envíos alguna vez? (para distinguir «0 real»
    // de «aún sin sincronizar» en la UI.)
    $r['submissions_synced'] = $r['submissions_synced_at'] !== null;
    unset($r['submissions_synced_at']);
}

ErrorResponse::ok($rows);
