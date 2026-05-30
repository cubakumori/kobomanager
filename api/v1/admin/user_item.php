<?php
/**
 * PUT /api/v1/admin/users/{id}   (solo admin)
 * Edita un usuario: { name, role, active, password? }.
 *   - password es opcional (solo se cambia si llega y tiene >= 8 caracteres).
 * Protecciones anti-bloqueo:
 *   - No puedes desactivarte ni quitarte el rol admin a ti mismo.
 *   - No se puede dejar el sistema sin ningún admin activo.
 */

$admin = Auth::requireAdmin();
$id    = (int) Request::param('id');

if (Request::method() !== 'PUT') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$user = DB::run('SELECT id, name, email, role, active FROM users WHERE id = ?', [$id])->fetch();
if (!$user) {
    ErrorResponse::send('NOT_FOUND', 'Usuario no encontrado');
}

$in    = Request::required(['name', 'email', 'role']);
$body  = Request::json();
$role  = $in['role'];
$email = $in['email'];
$active = array_key_exists('active', $body) ? (!empty($body['active']) ? 1 : 0) : (int) $user['active'];
$pass  = isset($body['password']) ? (string) $body['password'] : '';

if (!in_array($role, ['admin', 'viewer'], true)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Rol no válido');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Email no válido');
}
// Email único (excluyendo al propio usuario).
$dup = DB::run('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])->fetch();
if ($dup) {
    ErrorResponse::send('VALIDATION_ERROR', 'Ya existe otro usuario con ese email');
}

$losesAdmin = ($role !== 'admin') || ($active === 0); // ¿este cambio le quita capacidad de admin?

// No puedes auto-bloquearte.
if ($id === (int) $admin['id'] && $losesAdmin) {
    ErrorResponse::send('VALIDATION_ERROR', 'No puedes quitarte el rol admin ni desactivar tu propia cuenta.');
}

// No dejar el sistema sin admins activos.
if ((int) $user['active'] === 1 && $user['role'] === 'admin' && $losesAdmin) {
    $activeAdmins = (int) DB::run(
        "SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND active = 1"
    )->fetch()['c'];
    if ($activeAdmins <= 1) {
        ErrorResponse::send('VALIDATION_ERROR', 'Debe quedar al menos un administrador activo.');
    }
}

if ($pass !== '') {
    if (strlen($pass) < 8) {
        ErrorResponse::send('VALIDATION_ERROR', 'La contraseña debe tener al menos 8 caracteres');
    }
    DB::run(
        'UPDATE users SET name = ?, email = ?, role = ?, active = ?, password_hash = ? WHERE id = ?',
        [$in['name'], $email, $role, $active, password_hash($pass, PASSWORD_DEFAULT), $id]
    );
} else {
    DB::run(
        'UPDATE users SET name = ?, email = ?, role = ?, active = ? WHERE id = ?',
        [$in['name'], $email, $role, $active, $id]
    );
}

// Si se desactiva, cerrar sus sesiones activas.
if ($active === 0) {
    DB::run('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
}

Audit::log($admin['id'], 'edit_user', null, null, [
    'user_id'         => $id,
    'role'            => $role,
    'active'          => $active,
    'password_changed' => $pass !== '',
]);

ErrorResponse::ok([
    'id'     => $id,
    'name'   => $in['name'],
    'email'  => $email,
    'role'   => $role,
    'active' => (bool) $active,
]);
