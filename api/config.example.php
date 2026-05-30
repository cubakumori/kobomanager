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
// Secreto para firmar los JWT. 64 hex chars.
define('JWT_SECRET', 'REEMPLAZAR_POR_SECRETO_GENERADO');
// Duración de la sesión en segundos (8 horas).
define('JWT_TTL', 8 * 60 * 60);

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
