<?php
/**
 * CLI: aplicar AHORA la retención de envíos (`forms.retention_days`) sin esperar
 * al siguiente ciclo de sincronización.
 *
 * Uso:
 *   php api/cli/purge_submissions.php [form_id]
 *
 * Purga de la caché local los envíos más viejos que la ventana de cada formulario
 * con retención configurada (y su historial de revisión local). KoboToolbox no se
 * toca. Sin argumento recorre todos los formularios; con form_id, solo ese. En
 * operación normal no hace falta: la purga corre sola en cada sincronización.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/SubmissionSync.php';

$formId = isset($argv[1]) ? (int) $argv[1] : 0;

$sql    = 'SELECT id, name, retention_days FROM forms WHERE retention_days IS NOT NULL';
$params = [];
if ($formId > 0) {
    $sql     .= ' AND id = ?';
    $params[] = $formId;
}
$forms = DB::run($sql, $params)->fetchAll();

if (!$forms) {
    echo $formId > 0
        ? "El formulario $formId no existe o no tiene retención configurada.\n"
        : "Ningún formulario tiene retención configurada; nada que purgar.\n";
    exit(0);
}

$total = 0;
foreach ($forms as $f) {
    $n = SubmissionSync::purgeExpired((int) $f['id'], (int) $f['retention_days']);
    $total += $n;
    printf("  - %s (id %d, retención %d días): %d envío(s) purgado(s)\n", $f['name'], $f['id'], $f['retention_days'], $n);
}
echo "Total: $total envío(s) purgado(s) de la caché local (KoboToolbox intacto).\n";
