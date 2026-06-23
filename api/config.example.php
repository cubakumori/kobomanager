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
// Destino de los mensajes del formulario de contacto público (página «Apoyar»).
// Vacío = no se intenta notificar por email (el mensaje igual se guarda en BD).
define('CONTACT_TO', 'contacto@tudominio.com');

// --- App ---
define('APP_URL', 'http://localhost:5173');
// Orígenes permitidos para CORS (frontend en dev). Añade 'http://localhost:4173'
// si vas a probar el BUILD con `vite preview` (necesario para la PWA: el service
// worker solo se genera en build, no en dev).
define('CORS_ALLOWED_ORIGINS', ['http://localhost:5173']);
// Entorno: 'dev' muestra detalles de error; 'prod' los oculta.
define('APP_ENV', 'dev');

// --- Enlaces públicos (landing + página «Apoyar») ---
// URLs externas que muestra la parte pública. VACÍO = ese enlace/botón NO se
// muestra (una instancia recién clonada no enseña botones muertos ni pide
// donaciones a la cuenta de otra persona). Rellena solo los que tengas.
define('REPO_URL', '');           // p. ej. 'https://github.com/tu-usuario/kobomanager'
define('DONATE_PAYPAL_URL', '');  // p. ej. 'https://paypal.me/tu-usuario'
define('DONATE_KOFI_URL', '');    // p. ej. 'https://ko-fi.com/tu-usuario'

// --- Demo pública (opcional) ---
// true → instancia de DEMOSTRACIÓN: banner global visible y acciones sensibles
// bloqueadas (CRUD de cuentas Kobo y usuarios, contraseñas y sesiones, ajustes
// globales, edición de envíos y sync manual). Pensado junto a un cron que
// restaura la BD desde un dump semilla. Ver DEMO.md (runbook completo).
define('DEMO_MODE', false);
// Minutos del ciclo de reset (solo informativo: se muestra en el banner).
define('DEMO_RESET_MINUTES', 60);
// Credenciales de la demo que la portada ofrece al visitante, POR ROL (la
// etiqueta «Administrador»/«Viewer» la pone la app, traducida). Texto libre
// tipo 'email / contraseña'; vacío = esa línea no se muestra. Los usuarios
// deben EXISTIR (créalos desde la app; el email necesita dominio con punto).
// Convención sugerida (la que usa DEMO.md):
define('DEMO_LOGIN_ADMIN', '');   // p. ej. 'admin@demo.org / demo1234'
define('DEMO_LOGIN_VIEWER', '');  // p. ej. 'viewer@demo.org / demo1234'

// --- Zona horaria de visualización ---
// Kobo entrega `_submission_time` en UTC. En Estadísticas, la «Actividad por
// hora» y la «Actividad por día de la semana» se convierten a esta zona para
// mostrarlas en hora local. Identificador IANA (p. ej. 'America/Havana',
// 'Europe/Madrid'). Por defecto 'UTC' (sin conversión).
define('APP_TIMEZONE', 'UTC');
// Nombre legible para la UI (p. ej. 'La Habana', 'Madrid'). Vacío = se usa el
// identificador IANA. La interfaz muestra «Hora de {etiqueta} (UTC±N)».
define('APP_TIMEZONE_LABEL', '');
