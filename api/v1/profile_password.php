<?php
/**
 * POST /api/v1/profile/password   (usuario autenticado, su propia cuenta)
 * Body: { current_password, new_password }
 *
 * Cambio de contraseña voluntario desde el perfil. Verifica la contraseña
 * actual, aplica la política de contraseñas (lib/Password) y la guarda con
 * password_hash. Mantiene la sesión ACTUAL y cierra todas las DEMÁS: quien
 * cambia su contraseña porque sospecha un robo espera que la cookie robada deje
 * de valer (antes seguía viva hasta el tope absoluto de la sesión).
 */

$user = Auth::require();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$in      = Request::required(['current_password', 'new_password']);
$current = (string) $in['current_password'];
$new     = (string) $in['new_password'];

Password::enforce($new, [$user['email'], $user['name']]);

$row = DB::run('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
if (!$row || !password_verify($current, $row['password_hash'])) {
    ErrorResponse::send('PASSWORD_INCORRECT');
}

DB::run(
    'UPDATE users SET password_hash = ? WHERE id = ?',
    [password_hash($new, PASSWORD_DEFAULT), $user['id']]
);

// Cerrar las demás sesiones (la actual sigue: no es un flujo de recuperación).
$jti    = Auth::currentTokenId();
$closed = $jti !== null
    ? DB::run('DELETE FROM user_sessions WHERE user_id = ? AND token_id <> ?', [$user['id'], $jti])->rowCount()
    : 0;

Audit::log((int) $user['id'], 'password_changed', null, null, ['sessions_closed' => $closed]);

ErrorResponse::ok(['message' => 'ok', 'sessions_closed' => $closed]);
