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
                p.can_edit, p.can_validate,
                (SELECT COUNT(*) FROM submissions_cache sc WHERE sc.form_id = f.id) AS submission_count
         FROM forms f
         JOIN kobo_accounts a ON a.id = f.kobo_account_id
         JOIN user_form_permissions p ON p.form_id = f.id AND p.user_id = ? AND p.can_view = 1
         WHERE f.active = 1
         ORDER BY a.label, f.name',
        [$user['id']]
    )->fetchAll();
}

foreach ($rows as &$r) {
    $r['id']               = (int) $r['id'];
    $r['can_edit']         = (bool) $r['can_edit'];
    $r['can_validate']     = (bool) $r['can_validate'];
    $r['submission_count'] = (int) $r['submission_count'];
}

ErrorResponse::ok($rows);
