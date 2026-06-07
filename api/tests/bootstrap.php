<?php
/**
 * Bootstrap de los tests (PHPUnit).
 * Define la configuración apuntando a una BD de test SEPARADA (kobomanager_test)
 * y carga el autoloader de Composer (classmap de lib/).
 *
 * Requisitos previos (una vez):
 *   mysql -e "CREATE DATABASE kobomanager_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
 *   for f in db/*.sql; do mysql kobomanager_test < "$f"; done
 *   (y conceder privilegios al usuario de BD sobre kobomanager_test)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// --- Configuración de TEST (no es config.php; BD aislada) ---
define('DB_HOST', getenv('TEST_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('TEST_DB_PORT') ?: '3306');
define('DB_NAME', getenv('TEST_DB_NAME') ?: 'kobomanager_test');
define('DB_USER', getenv('TEST_DB_USER') ?: 'kobomanager');
define('DB_PASS', getenv('TEST_DB_PASS') ?: 'km_dev_2026');

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
