<?php
/**
 * GET /api/v1/public/share/{token}   (PÚBLICO, sin sesión)
 * Metadatos del enlace para pintar la vista pública: nombre del formulario, qué
 * expone y si requiere contraseña (y si ya está desbloqueado en esta petición).
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

ShareLink::throttle();

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
    'expose_stats'     => (bool) $link['expose_stats'],
    'expose_review_summary' => (bool) $link['expose_review_summary'],
    // Alcance por estado del enlace ('approved' o null): la vista pública lo anuncia
    // («solo envíos aprobados») para que los recuentos no desconcierten al visitante.
    'status_scope'     => ShareLink::statusScope($link),
    'requires_password'=> $needsPassword,
    'unlocked'         => $unlocked,
    'last_synced_at'   => $link['last_synced_at'],
    'default_locale'   => Settings::defaultLocale(),
]);
