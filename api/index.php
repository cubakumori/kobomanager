<?php
/**
 * Front controller de la API REST de KoboManager.
 *
 * Resuelve la ruta tras /api/v1/ y delega en el script correspondiente
 * dentro de /v1. Centraliza CORS, cabeceras JSON y manejo de errores.
 *
 * Layout de rutas (un archivo por recurso, parámetros vía query/segmento):
 *   /api/v1/health                  -> v1/health.php
 *   /api/v1/auth/login              -> v1/auth/login.php
 *   /api/v1/forms/{id}/submissions  -> v1/forms/submissions.php   (id en $ROUTE_PARAMS)
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/ErrorResponse.php';

// --- CORS (frontend en dev sobre otro origen) ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, CORS_ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Resolver path de la API ---
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = preg_replace('#^.*/api/v1/?#', '', $uri);   // quita prefijo hasta /api/v1/
$path = trim($path, '/');
$segments = $path === '' ? [] : explode('/', $path);

// --- Enrutado mínimo de la Fase 0 ---
// (Las rutas de auth/admin/forms se añaden en fases posteriores.)
try {
    switch (true) {
        case $segments === [] || $segments === ['health']:
            require __DIR__ . '/v1/health.php';
            break;

        default:
            ErrorResponse::send('NOT_FOUND', 'Ruta no encontrada: /' . $path);
    }
} catch (Throwable $e) {
    $detail = APP_ENV === 'dev' ? $e->getMessage() : null;
    ErrorResponse::send('INTERNAL_ERROR', $detail);
}
