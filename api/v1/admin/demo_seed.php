<?php
/**
 * /api/v1/admin/demo/seed   (solo admin; bloqueado en demo — ver Demo::BLOCKED)
 *   POST → genera la semilla de la demo en DEMO_SEED_PATH (export solo-datos,
 *          escritura atómica; ver lib/DemoSeed) y devuelve el resumen.
 *
 * Forma parte del bucle de mantenimiento de DEMO.md: se ejecuta con DEMO_MODE
 * APAGADO, tras dejar la instancia como deben encontrarla los visitantes.
 */

$admin = Auth::requireAdmin();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

if (DemoSeed::path() === '') {
    ErrorResponse::send('VALIDATION_ERROR', 'DEMO_SEED_PATH no está configurado en config.php.');
}

try {
    $res = DemoSeed::export();
} catch (Throwable $e) {
    // Endpoint solo-admin: el mensaje (ruta/permisos del filesystem) es accionable
    // para el operador y no revela nada que no esté ya en su config.
    ErrorResponse::send('INTERNAL_ERROR', $e->getMessage());
}

Audit::log($admin['id'], 'generate_demo_seed', null, null, [
    'rows'  => $res['rows'],
    'bytes' => $res['bytes'],
]);

ErrorResponse::ok($res + ['status' => DemoSeed::status()]);
