<?php
/**
 * CLI: reconcilia la BD con el esquema que espera el código (migración idempotente).
 *
 * Uso:
 *   php api/cli/migrate.php            aplica las columnas que falten
 *   php api/cli/migrate.php --dry-run  solo muestra lo que haría
 *
 * KoboManager no tiene migraciones por archivo: este comando compara la BD con
 * lib/SchemaCheck y aplica SOLO los ALTER de las columnas ausentes (las que ya
 * existen no se tocan). Es idempotente: ejecutarlo dos veces no cambia nada la
 * segunda vez. Pensado para correrlo en cada deploy. Solo AÑADE columnas / relaja
 * nullabilidad; nunca borra ni reescribe datos.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/SchemaCheck.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

try {
    $missing = SchemaCheck::missing();
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo consultar el esquema: " . $e->getMessage() . "\n");
    exit(2);
}

if (!$missing) {
    fwrite(STDOUT, "✓ Nada que aplicar: la base de datos ya está al día.\n");
    exit(0);
}

fwrite(STDOUT, ($dryRun ? "[dry-run] " : "") . "Columnas a aplicar: " . count($missing) . "\n");
$applied = 0;
foreach ($missing as $m) {
    fwrite(STDOUT, "  {$m['table']}.{$m['column']} … ");
    if ($dryRun) {
        fwrite(STDOUT, "(dry-run)\n");
        continue;
    }
    try {
        DB::run($m['fix']);
        $applied++;
        fwrite(STDOUT, "OK\n");
    } catch (Throwable $e) {
        // p. ej. carrera o estado parcial: se informa y se sigue con el resto.
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    }
}

if ($dryRun) {
    fwrite(STDOUT, "\n[dry-run] No se aplicó nada. Quita --dry-run para ejecutar.\n");
    exit(0);
}

$pending = SchemaCheck::missing();
if ($pending) {
    fwrite(STDERR, "\n✗ Aún faltan " . count($pending) . " columna(s); revisa los errores de arriba.\n");
    exit(1);
}
fwrite(STDOUT, "\n✓ Listo: se aplicaron $applied columna(s); la base de datos está al día.\n");
exit(0);
