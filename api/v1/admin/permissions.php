<?php
/**
 * /api/v1/admin/permissions   (solo admin)
 *
 *   GET  ?user_id=ID  → todos los formularios con el permiso actual de ese usuario
 *                       (los que no tienen fila aparecen con permisos en false).
 *   PUT  { user_id, permissions: [ { form_id, can_view, can_edit, can_validate } ] }
 *                     → guarda (upsert) los permisos del usuario.
 */

$admin = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $userId = (int) ($_GET['user_id'] ?? 0);
    if (!$userId) {
        ErrorResponse::send('VALIDATION_ERROR', 'Falta user_id');
    }
    $user = DB::run('SELECT id FROM users WHERE id = ?', [$userId])->fetch();
    if (!$user) {
        ErrorResponse::send('NOT_FOUND', 'Usuario no encontrado');
    }

    $rows = DB::run(
        'SELECT f.id AS form_id, f.name, a.label AS account_label,
                COALESCE(p.can_view, 0)     AS can_view,
                COALESCE(p.can_edit, 0)     AS can_edit,
                COALESCE(p.can_validate, 0) AS can_validate
         FROM forms f
         JOIN kobo_accounts a ON a.id = f.kobo_account_id
         LEFT JOIN user_form_permissions p ON p.form_id = f.id AND p.user_id = ?
         WHERE f.active = 1
         ORDER BY a.label, f.name',
        [$userId]
    )->fetchAll();

    foreach ($rows as &$r) {
        $r['form_id']      = (int) $r['form_id'];
        $r['can_view']     = (bool) $r['can_view'];
        $r['can_edit']     = (bool) $r['can_edit'];
        $r['can_validate'] = (bool) $r['can_validate'];
    }
    ErrorResponse::ok($rows);
}

if ($method === 'PUT') {
    $body   = Request::json();
    $userId = (int) ($body['user_id'] ?? 0);
    $perms  = $body['permissions'] ?? null;

    if (!$userId || !is_array($perms)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Faltan user_id o permissions');
    }
    $user = DB::run('SELECT id FROM users WHERE id = ?', [$userId])->fetch();
    if (!$user) {
        ErrorResponse::send('NOT_FOUND', 'Usuario no encontrado');
    }

    foreach ($perms as $p) {
        $formId = (int) ($p['form_id'] ?? 0);
        if (!$formId) continue;
        $canView     = !empty($p['can_view']) ? 1 : 0;
        $canEdit     = !empty($p['can_edit']) ? 1 : 0;
        $canValidate = !empty($p['can_validate']) ? 1 : 0;

        DB::run(
            'INSERT INTO user_form_permissions (user_id, form_id, can_view, can_edit, can_validate)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                can_view = VALUES(can_view),
                can_edit = VALUES(can_edit),
                can_validate = VALUES(can_validate)',
            [$userId, $formId, $canView, $canEdit, $canValidate]
        );
    }

    Audit::log($admin['id'], 'set_permissions', null, null, ['user_id' => $userId, 'forms' => count($perms)]);
    ErrorResponse::ok(['updated' => true]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
