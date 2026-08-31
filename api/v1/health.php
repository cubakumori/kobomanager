<?php
/**
 * GET /api/v1/health
 * Comprobación de estado. El sondeo PÚBLICO devuelve solo ok/degraded (sin
 * versión de PHP ni detalle de extensiones: eso orienta ataques dirigidos);
 * el detalle completo — checks, crons, sync y avisos de configuración — es
 * solo para administradores autenticados.
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

$out = [
    'status' => $checks['database'] ? 'ok' : 'degraded',
];

// Detalle de operación (checks del runtime, última ejecución de cada cron, estado
// de sincronización y avisos de configuración) solo para administradores.
$user = $checks['database'] ? Auth::currentUser() : null;
if ($user && ($user['role'] ?? '') === 'admin') {
    $out['checks'] = $checks;
    $out['cron']   = Settings::cronRuns();

    $forms = DB::run(
        "SELECT
            COUNT(*) AS total,
            SUM(active = 1) AS active,
            SUM(active = 1 AND sync_status = 'error') AS errors,
            MAX(CASE WHEN active = 1 THEN last_synced_at END) AS last_synced_at
         FROM forms"
    )->fetch();

    $subs = (int) DB::run('SELECT COUNT(*) AS c FROM submissions_cache')->fetch()['c'];

    $out['sync'] = [
        'forms_total'    => (int) $forms['total'],
        'forms_active'   => (int) $forms['active'],
        'forms_error'    => (int) $forms['errors'],
        'last_synced_at' => $forms['last_synced_at'],
        'submissions'    => $subs,
        'mail_configured'=> Settings::mailConfigured(),
    ];

    // Desajustes de config que en producción rompen cosas de forma silenciosa
    // (p. ej. APP_URL en localhost => los enlaces de los emails de recuperación
    // y de avisos apuntan a localhost). Solo se evalúan fuera de dev.
    $warnings = [];
    if (defined('APP_ENV') && APP_ENV === 'prod') {
        $host = strtolower((string) parse_url(APP_URL, PHP_URL_HOST));
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '') {
            $warnings[] = 'APP_URL apunta a ' . APP_URL . ': los enlaces de los emails (recuperación de contraseña, avisos) llevarán a esa URL, no a este servidor. Corrígelo en api/config.php.';
        }
        if (defined('COOKIE_SECURE') && COOKIE_SECURE === false) {
            $warnings[] = 'COOKIE_SECURE está en false con APP_ENV=prod: la cookie de sesión viajaría también por HTTP sin cifrar.';
        }
    }
    if ($warnings) {
        $out['config_warnings'] = $warnings;
    }
}

ErrorResponse::ok($out);
