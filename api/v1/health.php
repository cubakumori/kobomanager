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

$out = [
    'status' => $checks['database'] ? 'ok' : 'degraded',
    'checks' => $checks,
];

// Detalle de operación (última ejecución de cada cron + estado de sincronización)
// solo para administradores autenticados; el sondeo público se queda en lo básico.
$user = $checks['database'] ? Auth::currentUser() : null;
if ($user && ($user['role'] ?? '') === 'admin') {
    $out['cron'] = Settings::cronRuns();

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
}

ErrorResponse::ok($out);
