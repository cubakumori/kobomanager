<?php
/**
 * GET /api/v1/public/share/{token}   (PÚBLICO, sin sesión)
 * Metadatos del enlace para pintar la vista pública: nombre del formulario, qué
 * expone y si requiere contraseña (y si ya está desbloqueado en esta petición).
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$link  = ShareLink::resolve($token);
if ($link === null) {
    ErrorResponse::send('NOT_FOUND', 'Enlace no válido o caducado');
}

$needsPassword = ShareLink::hasPassword($link);
$unlocked      = !$needsPassword;
if ($needsPassword) {
    $ticket   = $_SERVER['HTTP_X_SHARE_TICKET'] ?? ($_GET['k'] ?? null);
    $unlocked = ShareLink::verifyTicket($ticket ? (string) $ticket : null, $token);
}

if ($unlocked) {
    ShareLink::recordAccess((int) $link['id']);
}

ErrorResponse::ok([
    'form'             => ['name' => $link['form_name']],
    'label'            => $link['label'],
    'expose_list'      => (bool) $link['expose_list'],
    'expose_detail'    => (bool) $link['expose_detail'],
    'expose_map'       => (bool) $link['expose_map'],
    'requires_password'=> $needsPassword,
    'unlocked'         => $unlocked,
    'default_locale'   => Settings::defaultLocale(),
]);
