<?php
/**
 * POST /api/v1/auth/login
 * Body: { email, password }
 * Verifica credenciales, crea sesión + JWT en cookie HttpOnly y devuelve el usuario.
 */

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$in = Request::required(['email', 'password']);

$user = DB::run(
    'SELECT id, name, email, role, password_hash, active FROM users WHERE email = ?',
    [$in['email']]
)->fetch();

// Mensaje genérico para no revelar si el email existe.
if (!$user || !$user['active'] || !password_verify($in['password'], $user['password_hash'])) {
    ErrorResponse::send('VALIDATION_ERROR', 'Credenciales incorrectas', 401);
}

Auth::issue($user);

ErrorResponse::ok([
    'id'    => (int) $user['id'],
    'name'  => $user['name'],
    'email' => $user['email'],
    'role'  => $user['role'],
]);
