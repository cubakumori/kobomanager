<?php
/**
 * Front controller de la API REST de KoboManager.
 *
 * Resuelve la ruta tras /api/v1/ y delega en el script correspondiente
 * dentro de /v1. Centraliza CORS, cabeceras JSON y manejo de errores.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/ErrorResponse.php';
require __DIR__ . '/lib/Request.php';
require __DIR__ . '/lib/TokenVault.php';
require __DIR__ . '/lib/Auth.php';
require __DIR__ . '/lib/Audit.php';

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
$route = trim($path, '/');

/**
 * Tabla de rutas: "ruta" => archivo en /v1.
 * Los parámetros dinámicos ({id}) y métodos se resuelven dentro de cada script.
 * Para Fase 1, rutas estáticas son suficientes.
 */
$routes = [
    ''                => 'health.php',
    'health'          => 'health.php',
    'auth/login'      => 'auth/login.php',
    'auth/logout'     => 'auth/logout.php',
    'auth/me'         => 'auth/me.php',
    'admin/users'     => 'admin/users.php',
    'admin/accounts'  => 'admin/accounts.php',
];

try {
    if (isset($routes[$route])) {
        require __DIR__ . '/v1/' . $routes[$route];
    } else {
        ErrorResponse::send('NOT_FOUND', 'Ruta no encontrada: /' . $route);
    }
} catch (Throwable $e) {
    $detail = APP_ENV === 'dev' ? $e->getMessage() : null;
    ErrorResponse::send('INTERNAL_ERROR', $detail);
}
