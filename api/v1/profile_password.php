<?php
/**
 * POST /api/v1/profile/password   (usuario autenticado, su propia cuenta)
 * Body: { current_password, new_password }
 *
 * Cambio de contraseña voluntario desde el perfil. Verifica la contraseña
 * actual, exige mínimo 8 caracteres para la nueva y la guarda con password_hash.
 * Mantiene la sesión actual (no es un flujo de recuperación).
 */

$user = Auth::require();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$in      = Request::required(['current_password', 'new_password']);
$current = (string) $in['current_password'];
$new     = (string) $in['new_password'];

if (strlen($new) < 8) {
    ErrorResponse::send('VALIDATION_ERROR', 'La contraseña debe tener al menos 8 caracteres');
}

$row = DB::run('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
if (!$row || !password_verify($current, $row['password_hash'])) {
    ErrorResponse::send('PASSWORD_INCORRECT');
}

DB::run(
    'UPDATE users SET password_hash = ? WHERE id = ?',
    [password_hash($new, PASSWORD_DEFAULT), $user['id']]
);

Audit::log((int) $user['id'], 'password_changed', null, null, null);

ErrorResponse::ok(['message' => 'ok']);
