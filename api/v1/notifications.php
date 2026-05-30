<?php
/**
 * /api/v1/notifications   (usuario autenticado, configura lo suyo)
 *   GET → formularios visibles para el usuario, con su flag daily_summary.
 *   PUT → { enabled: [form_id, ...] }  guarda qué formularios tienen resumen diario.
 *
 * notification_config no tiene clave única (user,form), así que en PUT se borran
 * las filas del usuario y se reinsertan solo las habilitadas (daily_summary = 1).
 */

$user = Auth::require();

/** IDs de formularios que el usuario puede ver (admin = todos los activos). */
function visible_form_ids(array $user): array {
    if ($user['role'] === 'admin') {
        $rows = DB::run('SELECT id FROM forms WHERE active = 1')->fetchAll();
    } else {
        $rows = DB::run(
            'SELECT f.id
             FROM forms f
             JOIN user_form_permissions p ON p.form_id = f.id AND p.user_id = ? AND p.can_view = 1
             WHERE f.active = 1',
            [$user['id']]
        )->fetchAll();
    }
    return array_map(fn($r) => (int) $r['id'], $rows);
}

if (Request::method() === 'GET') {
    $enabled = DB::run(
        'SELECT form_id FROM notification_config WHERE user_id = ? AND daily_summary = 1',
        [$user['id']]
    )->fetchAll();
    $enabledSet = array_flip(array_map(fn($r) => (int) $r['form_id'], $enabled));

    if ($user['role'] === 'admin') {
        $forms = DB::run(
            'SELECT f.id, f.name, a.label AS account_label
             FROM forms f JOIN kobo_accounts a ON a.id = f.kobo_account_id
             WHERE f.active = 1 ORDER BY a.label, f.name'
        )->fetchAll();
    } else {
        $forms = DB::run(
            'SELECT f.id, f.name, a.label AS account_label
             FROM forms f
             JOIN kobo_accounts a ON a.id = f.kobo_account_id
             JOIN user_form_permissions p ON p.form_id = f.id AND p.user_id = ? AND p.can_view = 1
             WHERE f.active = 1 ORDER BY a.label, f.name',
            [$user['id']]
        )->fetchAll();
    }

    $out = array_map(fn($f) => [
        'form_id'       => (int) $f['id'],
        'name'          => $f['name'],
        'account_label' => $f['account_label'],
        'daily_summary' => isset($enabledSet[(int) $f['id']]),
    ], $forms);

    ErrorResponse::ok($out);
}

if (Request::method() === 'PUT') {
    $enabled = Request::json()['enabled'] ?? [];
    if (!is_array($enabled)) {
        ErrorResponse::send('VALIDATION_ERROR', 'enabled debe ser una lista de form_id');
    }
    // Solo formularios que el usuario puede ver.
    $allowed = array_flip(visible_form_ids($user));
    $toEnable = array_values(array_unique(array_filter(
        array_map('intval', $enabled),
        fn($id) => isset($allowed[$id])
    )));

    $pdo = DB::conn();
    $pdo->beginTransaction();
    try {
        DB::run('DELETE FROM notification_config WHERE user_id = ?', [$user['id']]);
        foreach ($toEnable as $formId) {
            DB::run(
                'INSERT INTO notification_config (user_id, form_id, daily_summary) VALUES (?, ?, 1)',
                [$user['id'], $formId]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ErrorResponse::ok(['enabled' => $toEnable]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
