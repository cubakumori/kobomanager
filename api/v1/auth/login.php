<?php
/**
 * POST /api/v1/auth/login
 * Body: { email, password }
 * Verifica credenciales, crea sesión + JWT en cookie HttpOnly y devuelve el usuario.
 */

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Rate limiting: máx. 5 intentos fallidos por IP por minuto.
if (RateLimit::tooMany($ip, 5, 60)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}

$in = Request::required(['email', 'password']);

$user = DB::run(
    'SELECT id, name, email, role, password_hash, active FROM users WHERE email = ?',
    [$in['email']]
)->fetch();

// Mensaje genérico para no revelar si el email existe.
if (!$user || !$user['active'] || !password_verify($in['password'], $user['password_hash'])) {
    RateLimit::hit($ip);
    ErrorResponse::send('VALIDATION_ERROR', 'Credenciales incorrectas', 401);
}

// Login correcto: limpia los intentos de esta IP y emite la sesión.
RateLimit::clear($ip);
Auth::issue($user);

ErrorResponse::ok([
    'id'    => (int) $user['id'],
    'name'  => $user['name'],
    'email' => $user['email'],
    'role'  => $user['role'],
]);
