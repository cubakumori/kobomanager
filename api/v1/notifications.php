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
    // Preferencia EXPLÍCITA del usuario por formulario (0/1). La ausencia de fila
    // significa «sin preferencia» → se aplica el valor por defecto global.
    $rows = DB::run(
        'SELECT form_id, daily_summary FROM notification_config WHERE user_id = ?',
        [$user['id']]
    )->fetchAll();
    $explicit = [];
    foreach ($rows as $r) {
        $explicit[(int) $r['form_id']] = (int) $r['daily_summary'];
    }
    $defaultOn = Settings::notificationsDefaultOn();

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
        // Efectivo = preferencia explícita si existe; si no, el valor por defecto.
        'daily_summary' => array_key_exists((int) $f['id'], $explicit)
            ? (bool) $explicit[(int) $f['id']]
            : $defaultOn,
    ], $forms);

    ErrorResponse::ok(['forms' => $out, 'default_on' => $defaultOn]);
}

if (Request::method() === 'PUT') {
    $enabled = Request::json()['enabled'] ?? [];
    if (!is_array($enabled)) {
        ErrorResponse::send('VALIDATION_ERROR', 'enabled debe ser una lista de form_id');
    }
    // Formularios que el usuario puede ver (activos). Se guarda una preferencia
    // EXPLÍCITA (1/0) para cada uno, de modo que un «desmarcado» persista frente al
    // valor por defecto global (sin fila = sin preferencia = usa el default). Los
    // formularios no visibles en este momento (p. ej. nuevos) heredarán el default.
    $visible    = visible_form_ids($user);
    $enabledSet = array_flip(array_map('intval', $enabled));

    $pdo = DB::conn();
    $pdo->beginTransaction();
    try {
        DB::run('DELETE FROM notification_config WHERE user_id = ?', [$user['id']]);
        foreach ($visible as $formId) {
            DB::run(
                'INSERT INTO notification_config (user_id, form_id, daily_summary) VALUES (?, ?, ?)',
                [$user['id'], $formId, isset($enabledSet[$formId]) ? 1 : 0]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ErrorResponse::ok(['enabled' => array_values(array_filter($visible, fn($id) => isset($enabledSet[$id])))]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
