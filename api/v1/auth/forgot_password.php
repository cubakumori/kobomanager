<?php
/**
 * POST /api/v1/auth/forgot-password
 * Body: { email }
 *
 * Inicia el flujo «olvidé mi contraseña». Por seguridad:
 *   - Respuesta SIEMPRE genérica (no revela si el email existe ni si el flujo
 *     está habilitado / el email está configurado).
 *   - La respuesta se ENVÍA ANTES de hacer ningún trabajo dependiente del caso
 *     (lookup, token, email): si el email existe, Mailer::send es una llamada
 *     cURL síncrona de cientos de ms y esa diferencia de latencia era un oráculo
 *     fiable de «esta cuenta existe». Con la respuesta ya en el aire, todos los
 *     caminos tardan lo mismo a ojos del cliente.
 *   - Rate-limited por IP (bucket 'forgot' de rate_hits; ya no comparte
 *     login_attempts con el login: cada flujo tiene su presupuesto).
 *   - Si el flujo está deshabilitado, no hace nada (responde genérico).
 *   - Genera un token aleatorio; guarda solo su HASH (sha256) + expiración;
 *     invalida tokens previos del usuario. El token en claro viaja solo en el email.
 *   - Si RESEND_API_KEY está vacío, Mailer no envía y devuelve false (degradado
 *     sin error): el usuario igual ve la respuesta genérica.
 */

require_once __DIR__ . '/../../lib/Mailer.php';

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

/** Caducidad del token de reset, en segundos (1 hora). */
const RESET_TTL = 3600;

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Rate-limit: máx. 5 solicitudes por IP cada 15 min.
if (RateLimit::tooManyBucket($ip, 'forgot', 5, 900)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}
RateLimit::hitBucket($ip, 'forgot');

$in    = Request::required(['email']);
$email = $in['email'];

// Respuesta genérica ÚNICA, enviada YA (idéntica en todos los casos). El script
// sigue trabajando después con la conexión cerrada de cara al cliente.
ignore_user_abort(true);
$body = json_encode(['success' => true, 'data' => ['message' => 'ok']], JSON_UNESCAPED_UNICODE);
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($body));
header('Connection: close');
echo $body;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();          // FPM: cierra la respuesta de verdad
} else {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();                          // Apache mod_php / php -S: vacía los buffers
}

// ---- A partir de aquí, nada de output: el cliente ya tiene su respuesta. ----

// Flujo deshabilitado por el admin → no hacer nada.
if (!Settings::passwordResetEnabled()) {
    exit;
}

$user = DB::run(
    'SELECT id, name, email, locale FROM users WHERE email = ? AND active = 1',
    [$email]
)->fetch();

// Email desconocido o inactivo → nada que enviar.
if (!$user) {
    exit;
}

// Token de un solo uso: 32 bytes aleatorios → 64 hex en el enlace; en BD solo el hash.
$token     = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);

// Invalidar cualquier token previo del usuario (solo uno activo a la vez).
DB::run('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]);
DB::run(
    'INSERT INTO password_resets (user_id, token_hash, expires_at, ip)
     VALUES (?, ?, FROM_UNIXTIME(?), ?)',
    [$user['id'], $tokenHash, time() + RESET_TTL, $ip]
);

Audit::log((int) $user['id'], 'password_reset_requested', null, null, ['ip' => $ip]);

// Enlace a la página pública de reset del frontend (APP_URL: en un servidor debe
// ser la URL pública de la instancia — ver el aviso de config de /health).
$link = rtrim(APP_URL, '/') . '/reset-password?token=' . $token;
[$subject, $html, $text] = build_reset_email($user['name'], $link, ($user['locale'] ?: Settings::defaultLocale()));
Mailer::send($user['email'], $subject, $html, $text);   // degradado sin error si no hay clave

exit;

/** Construye [asunto, html, texto] del email de recuperación, en es|en. */
function build_reset_email(string $name, string $link, string $locale): array {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    if ($locale === 'en') {
        $subject = '[KoboManager] Reset your password';
        $text = "Hi $name,\n\nWe received a request to reset your KoboManager password. "
              . "Open this link to choose a new one (valid for 1 hour, single use):\n\n$link\n\n"
              . "If you didn't request this, you can ignore this email; your password won't change.\n";
        $html = "<p>Hi $safeName,</p>"
              . "<p>We received a request to reset your KoboManager password. "
              . "Click the button to choose a new one. The link is valid for <strong>1 hour</strong> and can be used once.</p>"
              . "<p><a href=\"$safeLink\" style=\"display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none\">Reset password</a></p>"
              . "<p style=\"color:#888;font-size:12px\">Or copy this link: $safeLink</p>"
              . "<hr><p style=\"color:#888;font-size:12px\">If you didn't request this, ignore this email; your password won't change.</p>";
        return [$subject, $html, $text];
    }

    $subject = '[KoboManager] Recupera tu contraseña';
    $text = "Hola $name,\n\nRecibimos una solicitud para restablecer tu contraseña de KoboManager. "
          . "Abre este enlace para elegir una nueva (válido 1 hora, un solo uso):\n\n$link\n\n"
          . "Si no fuiste tú, ignora este email; tu contraseña no cambiará.\n";
    $html = "<p>Hola $safeName,</p>"
          . "<p>Recibimos una solicitud para restablecer tu contraseña de KoboManager. "
          . "Pulsa el botón para elegir una nueva. El enlace es válido <strong>1 hora</strong> y se puede usar una vez.</p>"
          . "<p><a href=\"$safeLink\" style=\"display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none\">Restablecer contraseña</a></p>"
          . "<p style=\"color:#888;font-size:12px\">O copia este enlace: $safeLink</p>"
          . "<hr><p style=\"color:#888;font-size:12px\">Si no fuiste tú, ignora este email; tu contraseña no cambiará.</p>";
    return [$subject, $html, $text];
}
