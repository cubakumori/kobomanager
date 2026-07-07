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

// Config: por defecto config.php; KM_CONFIG permite apuntar a otra (p. ej. la BD de test),
// igual que el front controller y el instalador.
require getenv('KM_CONFIG') ?: __DIR__ . '/../config.php';
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

fwrite(STDOUT, ($dryRun ? "[dry-run] " : "") . "Cambios a aplicar: " . count($missing) . "\n");
$applied      = 0;
$appliedCols  = [];
foreach ($missing as $m) {
    $what = $m['column'] === null ? "tabla {$m['table']}" : "{$m['table']}.{$m['column']}";
    fwrite(STDOUT, "  $what … ");
    if ($dryRun) {
        fwrite(STDOUT, "(dry-run)\n");
        continue;
    }
    try {
        DB::run($m['fix']);
        // Backfill declarado (puebla la columna recién añadida desde datos existentes).
        if (!empty($m['backfill'])) {
            DB::run($m['backfill']);
        }
        $applied++;
        $appliedCols[] = $m['table'] . '.' . ($m['column'] ?? '');
        fwrite(STDOUT, "OK\n");
    } catch (Throwable $e) {
        // p. ej. carrera o estado parcial: se informa y se sigue con el resto.
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    }
}

// Columnas materializadas del payload: su backfill exige PHP (duración con las
// claves meta del esquema, geo con Geo::features). Solo si acaban de añadirse.
$needsRecompute = !$dryRun && array_intersect(
    array_map(fn($c) => "submissions_cache.$c", SchemaCheck::RECOMPUTE_COLUMNS),
    $appliedCols
) !== [];
if ($needsRecompute) {
    require_once __DIR__ . '/../lib/Geo.php';
    require_once __DIR__ . '/../lib/Derived.php';
    require_once __DIR__ . '/../lib/FormSchema.php';
    require_once __DIR__ . '/../lib/SubmissionSearch.php';
    require_once __DIR__ . '/../lib/KoboClient.php';
    require_once __DIR__ . '/../lib/ValidationStatus.php';
    require_once __DIR__ . '/../lib/SubmissionSync.php';
    fwrite(STDOUT, "  Recalculando columnas materializadas de submissions_cache … ");
    try {
        $n = SubmissionSync::recomputeCacheColumns();
        fwrite(STDOUT, "OK ($n filas)\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    }
}

if ($dryRun) {
    fwrite(STDOUT, "\n[dry-run] No se aplicó nada. Quita --dry-run para ejecutar.\n");
    exit(0);
}

$pending = SchemaCheck::missing();
if ($pending) {
    fwrite(STDERR, "\n✗ Aún faltan " . count($pending) . " cambio(s); revisa los errores de arriba.\n");
    exit(1);
}
fwrite(STDOUT, "\n✓ Listo: se aplicaron $applied cambio(s); la base de datos está al día.\n");
exit(0);
