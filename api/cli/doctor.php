<?php
/**
 * CLI: diagnóstico de desfase entre el ESQUEMA de la BD y el CÓDIGO.
 *
 * Uso:
 *   php api/cli/doctor.php
 *
 * Compara la base de datos con las columnas que el código de esta versión espera
 * (lib/SchemaCheck) y, si falta alguna, imprime cuáles y el ALTER exacto para
 * aplicarlas. NO modifica nada (para aplicarlas: php api/cli/migrate.php).
 *
 * Pensado para ejecutarlo tras cada deploy/actualización. Código de salida 1 si hay
 * desfase (útil en scripts de despliegue), 0 si la BD está al día.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/SchemaCheck.php';

try {
    $missing = SchemaCheck::missing();
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo consultar el esquema: " . $e->getMessage() . "\n");
    exit(2);
}

if (!$missing) {
    fwrite(STDOUT, "✓ Esquema al día: la base de datos tiene todas las columnas que el código espera.\n");
    exit(0);
}

fwrite(STDOUT, "✗ La base de datos está DESACTUALIZADA. Faltan " . count($missing) . " columna(s):\n\n");
foreach ($missing as $m) {
    $why = !empty($m['nullable']) ? ' (debe admitir NULL)' : '';
    fwrite(STDOUT, "  - {$m['table']}.{$m['column']}{$why}  [desde v{$m['since']}]\n");
}
fwrite(STDOUT, "\nAplica estos ALTER (o ejecuta: php api/cli/migrate.php):\n\n");
foreach ($missing as $m) {
    fwrite(STDOUT, "  {$m['fix']};\n");
}
fwrite(STDOUT, "\n");
exit(1);
