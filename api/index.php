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
require __DIR__ . '/lib/KoboClient.php';

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
 * Tabla de rutas: "patrón" => archivo en /v1.
 * Un segmento ":nombre" captura un parámetro dinámico, accesible vía Request::param().
 * El método HTTP se resuelve dentro de cada script.
 */
$routes = [
    ''                          => 'health.php',
    'health'                    => 'health.php',
    'auth/login'                => 'auth/login.php',
    'auth/logout'               => 'auth/logout.php',
    'auth/me'                   => 'auth/me.php',
    'admin/users'               => 'admin/users.php',
    'admin/accounts'            => 'admin/accounts.php',
    'admin/forms'               => 'admin/forms.php',
    'admin/forms/sync'          => 'admin/forms_sync.php',
    'admin/permissions'         => 'admin/permissions.php',
    'forms'                     => 'forms/index.php',
    'forms/:id/submissions'     => 'forms/submissions.php',
    'forms/:id/stats'           => 'forms/stats.php',
    'submissions/:id'           => 'submissions/item.php',
    'submissions/:id/review'    => 'submissions/review.php',
];

/** Empareja la ruta solicitada contra los patrones; devuelve [archivo, params] o [null, []]. */
function match_route(string $route, array $routes): array {
    $reqSegments = $route === '' ? [] : explode('/', $route);
    foreach ($routes as $pattern => $file) {
        $patSegments = $pattern === '' ? [] : explode('/', $pattern);
        if (count($patSegments) !== count($reqSegments)) continue;

        $params = [];
        $ok = true;
        foreach ($patSegments as $i => $seg) {
            if (str_starts_with($seg, ':')) {
                $params[substr($seg, 1)] = $reqSegments[$i];
            } elseif ($seg !== $reqSegments[$i]) {
                $ok = false;
                break;
            }
        }
        if ($ok) return [$file, $params];
    }
    return [null, []];
}

try {
    [$file, $params] = match_route($route, $routes);
    if ($file !== null) {
        Request::$params = $params;
        require __DIR__ . '/v1/' . $file;
    } else {
        ErrorResponse::send('NOT_FOUND', 'Ruta no encontrada: /' . $route);
    }
} catch (Throwable $e) {
    $detail = APP_ENV === 'dev' ? $e->getMessage() : null;
    ErrorResponse::send('INTERNAL_ERROR', $detail);
}
