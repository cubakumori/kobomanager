<?php
/**
 * /api/v1/admin/users   (solo admin)
 *   GET  → lista de usuarios de la app
 *   POST → crea usuario { name, email, password, role? }
 */

$admin = Auth::requireAdmin();

if (Request::method() === 'GET') {
    $rows = DB::run(
        'SELECT u.id, u.name, u.email, u.role, u.active, u.created_at,
                (SELECT COUNT(*) FROM user_sessions s
                 WHERE s.user_id = u.id AND s.expires_at > NOW()) AS active_sessions
         FROM users u ORDER BY u.created_at DESC'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id']              = (int) $r['id'];
        $r['active']          = (bool) $r['active'];
        $r['active_sessions'] = (int) $r['active_sessions'];
    }
    ErrorResponse::ok($rows);
}

if (Request::method() === 'POST') {
    $in = Request::required(['name', 'email', 'password']);
    $role = Request::json()['role'] ?? 'viewer';

    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Email no válido');
    }
    if (!in_array($role, ['admin', 'viewer'], true)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Rol no válido');
    }
    if (strlen($in['password']) < 8) {
        ErrorResponse::send('VALIDATION_ERROR', 'La contraseña debe tener al menos 8 caracteres');
    }

    $exists = DB::run('SELECT id FROM users WHERE email = ?', [$in['email']])->fetch();
    if ($exists) {
        ErrorResponse::send('VALIDATION_ERROR', 'Ya existe un usuario con ese email');
    }

    DB::run(
        'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
        [$in['name'], $in['email'], password_hash($in['password'], PASSWORD_DEFAULT), $role]
    );
    $id = (int) DB::conn()->lastInsertId();

    Audit::log($admin['id'], 'create_user', null, null, ['user_id' => $id, 'email' => $in['email'], 'role' => $role]);

    ErrorResponse::ok([
        'id'     => $id,
        'name'   => $in['name'],
        'email'  => $in['email'],
        'role'   => $role,
        'active' => true,
    ], 201);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
