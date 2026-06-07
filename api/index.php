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
require __DIR__ . '/lib/RateLimit.php';
require __DIR__ . '/lib/Settings.php';
require __DIR__ . '/lib/KoboClient.php';
require __DIR__ . '/lib/FormSchema.php';
require __DIR__ . '/lib/Geo.php';
require __DIR__ . '/lib/Attachments.php';
require __DIR__ . '/lib/Derived.php';
require __DIR__ . '/lib/RowScope.php';
require __DIR__ . '/lib/SubmissionSearch.php';
require __DIR__ . '/lib/ShareLink.php';
require __DIR__ . '/lib/SubmissionSync.php';

// --- CORS (frontend en dev sobre otro origen) ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, CORS_ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Protección CSRF ---
// La cookie de sesión es SameSite=Lax (ya bloquea el envío en POST cross-site),
// pero reforzamos comprobando el origen en métodos que modifican estado: el
// Origin/Referer debe coincidir con un origen permitido. Una petición CSRF desde
// otro sitio llevará un Origin ajeno (→ bloqueada); las del propio frontend
// coinciden. Si no hay Origin ni Referer (clientes no-navegador: cron/CLI, que no
// arrastran la cookie de la víctima) no se aplica.
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) ? 'https' : 'http';
    $selfOrigin = isset($_SERVER['HTTP_HOST']) ? $scheme . '://' . $_SERVER['HTTP_HOST'] : null;
    $allowedOrigins = array_values(array_filter(array_merge(CORS_ALLOWED_ORIGINS, [$selfOrigin])));

    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($reqOrigin === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $p = parse_url($_SERVER['HTTP_REFERER']);
        if (isset($p['scheme'], $p['host'])) {
            $reqOrigin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
    }
    if ($reqOrigin !== '' && !in_array($reqOrigin, $allowedOrigins, true)) {
        ErrorResponse::send('CSRF_BLOCKED');
    }
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
    'config'                    => 'config.php',
    'auth/login'                => 'auth/login.php',
    'auth/logout'               => 'auth/logout.php',
    'auth/me'                   => 'auth/me.php',
    'auth/forgot-password'      => 'auth/forgot_password.php',
    'auth/reset-password'       => 'auth/reset_password.php',
    'admin/users'               => 'admin/users.php',
    'admin/users/:id'           => 'admin/user_item.php',
    'admin/users/:id/sessions'  => 'admin/user_sessions.php',
    'admin/accounts'            => 'admin/accounts.php',
    'admin/accounts/:id'        => 'admin/account_item.php',
    'admin/forms'               => 'admin/forms.php',
    'admin/forms/sync'          => 'admin/forms_sync.php',
    'admin/forms/:id'           => 'admin/form_item.php',
    'admin/forms/:id/sync'      => 'admin/form_sync_one.php',
    'admin/forms/:id/enketo'    => 'admin/form_enketo.php',
    'admin/permissions'         => 'admin/permissions.php',
    'admin/forms/:id/scope-fields' => 'admin/scope_fields.php',
    'admin/settings'            => 'admin/settings.php',
    'admin/shares'              => 'admin/shares.php',
    'admin/shares/:id'          => 'admin/share_item.php',
    'admin/audit'               => 'admin/audit.php',
    'audit/me'                  => 'audit/me.php',
    'notifications'             => 'notifications.php',
    'profile'                   => 'profile.php',
    'profile/password'          => 'profile_password.php',
    'profile/sessions'          => 'profile_sessions.php',
    'forms'                     => 'forms/index.php',
    'forms/:id/enketo'          => 'forms/enketo.php',
    'forms/:id/sync'            => 'forms/sync.php',
    'forms/:id/submissions'     => 'forms/submissions.php',
    'forms/:id/stats'           => 'forms/stats.php',
    'forms/:id/map'             => 'forms/map.php',
    'forms/:id/review'          => 'forms/review_batch.php',
    'forms/:id/export'          => 'forms/export.php',
    'submissions/:id'           => 'submissions/item.php',
    'submissions/:id/review'    => 'submissions/review.php',
    'submissions/:id/attachments/:attId' => 'submissions/attachment.php',
    // Enlaces públicos de solo lectura (sin sesión): el :token es el secreto del enlace.
    'public/share/:token'                  => 'public/share.php',
    'public/share/:token/unlock'           => 'public/share_unlock.php',
    'public/share/:token/submissions'      => 'public/share_submissions.php',
    'public/share/:token/submissions/:uid' => 'public/share_submission.php',
    'public/share/:token/submissions/:uid/attachments/:attId' => 'public/share_attachment.php',
    'public/share/:token/map'              => 'public/share_map.php',
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
