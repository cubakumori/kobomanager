<?php
/**
 * POST /api/v1/public/share/{token}/unlock   (PÚBLICO, sin sesión)
 * Body: { password }
 * Verifica la contraseña del enlace y, si es correcta, devuelve un ticket firmado
 * de vida corta que el cliente adjunta (cabecera X-Share-Ticket o ?k=) en las
 * peticiones de datos. Rate-limit por IP para frenar la fuerza bruta.
 */

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (RateLimit::tooMany($ip, 10, 60)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}

$link = ShareLink::resolve($token);
if ($link === null) {
    ErrorResponse::send('NOT_FOUND', 'Enlace no válido o caducado');
}

$password = (string) (Request::json()['password'] ?? '');

if (!ShareLink::hasPassword($link)) {
    // Enlace sin contraseña: no necesita ticket; responde como desbloqueado.
    ErrorResponse::ok(['ticket' => null, 'unlocked' => true]);
}

if (!ShareLink::verifyPassword($link, $password)) {
    RateLimit::hit($ip);   // cuenta el fallo (compartido con el throttle de auth, como forgot-password)
    ErrorResponse::send('PASSWORD_INCORRECT', 'Contraseña incorrecta');
}

ErrorResponse::ok(['ticket' => ShareLink::issueTicket($token), 'unlocked' => true]);
