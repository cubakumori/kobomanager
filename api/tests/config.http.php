<?php
/**
 * Configuración de TEST (constantes equivalentes a config.php, pero apuntando a la
 * BD AISLADA `kobomanager_test` y con secretos fijos de test).
 *
 * Fuente ÚNICA de la config de pruebas: la usan tanto los tests unitarios en proceso
 * (vía tests/bootstrap.php) como el servidor `php -S` efímero que levantan los tests
 * de integración HTTP (vía la variable de entorno KM_CONFIG → api/index.php).
 *
 * No carga el autoloader ni abre conexión: solo define constantes (idéntico contrato
 * que config.php). Los valores de BD se pueden sobreescribir con env TEST_DB_*.
 */

declare(strict_types=1);

// Nota: usar `=== false` (no `?:`) para que un valor vacío sea respetado — en CI el root
// de MariaDB NO tiene contraseña (TEST_DB_PASS=''), y `'' ?: 'x'` daría 'x' por error.
$envOr = static fn(string $k, string $default): string
    => (($v = getenv($k)) !== false) ? $v : $default;

define('DB_HOST', $envOr('TEST_DB_HOST', '127.0.0.1'));
define('DB_PORT', $envOr('TEST_DB_PORT', '3306'));
define('DB_NAME', $envOr('TEST_DB_NAME', 'kobomanager_test'));
define('DB_USER', $envOr('TEST_DB_USER', 'kobomanager'));
define('DB_PASS', $envOr('TEST_DB_PASS', 'km_dev_2026'));

// Clave sodium (32 bytes en hex) solo para tests.
define('CONFIG_TOKEN_KEY', '52904b849e153a6bbe35d5f7676bf1d7f2580c78a06a5c2c1a415d57d382b9dc');
define('CONFIG_TOKEN_KEY_NEW', '');
define('JWT_SECRET', str_repeat('ab', 32)); // 64 hex chars
define('JWT_TTL', 3600);
define('SESSION_ABSOLUTE_TTL', 7 * 24 * 60 * 60);
define('SESSION_REFRESH_THRESHOLD', JWT_TTL / 2);

define('COOKIE_NAME', 'km_session');
define('COOKIE_SECURE', false);
define('COOKIE_SAMESITE', 'Lax');

define('RESEND_API_KEY', '');
define('MAIL_FROM', 'KoboManager <noreply@test.local>');
define('APP_URL', 'http://localhost:5173');
define('CORS_ALLOWED_ORIGINS', ['http://localhost:5173']);
define('APP_ENV', 'dev');
