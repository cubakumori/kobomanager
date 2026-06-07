<?php
/**
 * CLI: re-poblar la columna `submissions_cache.search_text` de todos los envíos
 * cacheados (texto plano buscable, índice FULLTEXT).
 *
 * Uso:
 *   php api/cli/rebuild_search_text.php [form_id]
 *
 * Útil para (a) rellenar filas cacheadas antes de M4a, y (b) recalcular si cambia
 * la lógica de proyección (lib/SubmissionSearch::textFor). En operación normal la
 * columna se mantiene sola en cada sync / edición; este script no es necesario a
 * diario.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/SubmissionSearch.php';

$formId = isset($argv[1]) ? (int) $argv[1] : 0;

$sql    = 'SELECT id, json_payload FROM submissions_cache';
$params = [];
if ($formId > 0) {
    $sql      .= ' WHERE form_id = ?';
    $params[]  = $formId;
}

$rows = DB::run($sql, $params)->fetchAll();
$n    = 0;
foreach ($rows as $row) {
    $payload = json_decode((string) $row['json_payload'], true);
    if (!is_array($payload)) continue;
    DB::run(
        'UPDATE submissions_cache SET search_text = ? WHERE id = ?',
        [SubmissionSearch::textFor($payload), $row['id']]
    );
    $n++;
}

echo "search_text recalculado en $n envío(s)" . ($formId ? " (form $formId)" : '') . ".\n";
