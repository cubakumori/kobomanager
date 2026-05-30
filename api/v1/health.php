<?php
/**
 * GET /api/v1/health
 * Comprobación de estado: PHP, extensiones críticas y conexión a la BD.
 */

$checks = [
    'php_version' => PHP_VERSION,
    'sodium'      => extension_loaded('sodium'),
    'pdo_mysql'   => extension_loaded('pdo_mysql'),
    'database'    => false,
];

try {
    DB::conn()->query('SELECT 1');
    $checks['database'] = true;
} catch (Throwable $e) {
    $checks['database'] = false;
}

ErrorResponse::ok([
    'status' => $checks['database'] ? 'ok' : 'degraded',
    'checks' => $checks,
]);
