<?php
/**
 * POST /api/v1/public/share/{token}/unlock   (PÚBLICO, sin sesión)
 * Body: { password }
 * Verifica la contraseña del enlace y, si es correcta, devuelve un ticket firmado
 * de vida corta que el cliente adjunta (cabecera X-Share-Ticket o ?k=) en las
 * peticiones de datos.
 *
 * Fuerza bruta frenada en dos capas (bucket propio en rate_hits; ya NO comparte
 * login_attempts con el login, así los flujos no se pisan el presupuesto):
 *   - por IP: 5 fallos/min (rotar de enlace no ayuda);
 *   - por ENLACE entre todas las IPs: 30 fallos/10 min (una botnet que rota IPs
 *     contra un mismo enlace tampoco pasa de ahí; un equipo legítimo que escribe
 *     bien la contraseña no consume fallos).
 */

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (RateLimit::tooManyBucket($ip, 'share_unlock', 5, 60)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}

$link = ShareLink::resolve($token);
if ($link === null) {
    ErrorResponse::send('NOT_FOUND', 'Enlace no válido o caducado');
}

// Contador POR ENLACE (ip comodín '*'): el id es interno y no viaja al cliente.
$linkBucket = 'unlock.' . (int) $link['id'];
if (RateLimit::tooManyBucket('*', $linkBucket, 30, 600)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}

$password = (string) (Request::json()['password'] ?? '');

if (!ShareLink::hasPassword($link)) {
    // Enlace sin contraseña: no necesita ticket; responde como desbloqueado.
    ErrorResponse::ok(['ticket' => null, 'unlocked' => true]);
}

if (!ShareLink::verifyPassword($link, $password)) {
    RateLimit::hitBucket($ip, 'share_unlock');
    RateLimit::hitBucket('*', $linkBucket);
    ErrorResponse::send('PASSWORD_INCORRECT', 'Contraseña incorrecta');
}

ErrorResponse::ok(['ticket' => ShareLink::issueTicket($token), 'unlocked' => true]);
