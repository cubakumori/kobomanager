<?php
/**
 * KoboManager — Configuración.
 *
 * Copia este archivo a `config.php` y rellena los valores reales.
 * `config.php` NUNCA se versiona (ver .gitignore).
 *
 * Generar TOKEN_KEY (una sola vez):
 *   php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
 * Generar JWT_SECRET (una sola vez):
 *   php -r 'echo bin2hex(random_bytes(32));'
 */

// --- Base de datos ---
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'kobomanager');
define('DB_USER', 'kobomanager');
define('DB_PASS', 'changeme');

// --- Seguridad ---
// Clave maestra para cifrar tokens de Kobo (libSodium). 64 hex chars.
define('CONFIG_TOKEN_KEY', 'REEMPLAZAR_POR_CLAVE_GENERADA');
// Clave NUEVA solo durante la rotación de CONFIG_TOKEN_KEY (ver DEPLOY §12 y
// cli/rotate_token_key.php). En operación normal déjala vacía.
define('CONFIG_TOKEN_KEY_NEW', '');
// Secreto para firmar los JWT. 64 hex chars.
define('JWT_SECRET', 'REEMPLAZAR_POR_SECRETO_GENERADO');
// Sesión deslizante: se renueva con la actividad hasta un tope absoluto.
define('JWT_TTL', 8 * 60 * 60);                   // inactividad máxima (idle TTL); 8 h
define('SESSION_ABSOLUTE_TTL', 7 * 24 * 60 * 60); // vida máxima desde el login → re-login; 7 d
define('SESSION_REFRESH_THRESHOLD', JWT_TTL / 2); // renueva la cookie cuando queda menos de esto

// --- Cookies ---
define('COOKIE_NAME', 'km_session');
// En producción debe ser true (requiere HTTPS).
define('COOKIE_SECURE', false);
define('COOKIE_SAMESITE', 'Lax');

// --- Email (Resend) ---
define('RESEND_API_KEY', '');
define('MAIL_FROM', 'KoboManager <noreply@tudominio.com>');

// --- App ---
define('APP_URL', 'http://localhost:5173');
// Orígenes permitidos para CORS (frontend en dev).
define('CORS_ALLOWED_ORIGINS', ['http://localhost:5173']);
// Entorno: 'dev' muestra detalles de error; 'prod' los oculta.
define('APP_ENV', 'dev');
