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
require __DIR__ . '/../lib/FormSchema.php';

$formId = isset($argv[1]) ? (int) $argv[1] : 0;

// Se procesa POR FORMULARIO para reutilizar su mapa de etiquetas de opción (el
// texto buscable incluye ahora código + etiqueta legible; ver SubmissionSearch::textFor).
$formSql    = 'SELECT id, schema_json FROM forms';
$formParams = [];
if ($formId > 0) {
    $formSql     .= ' WHERE id = ?';
    $formParams[] = $formId;
}
$forms = DB::run($formSql, $formParams)->fetchAll();

$n = 0;
foreach ($forms as $form) {
    $schema       = $form['schema_json'] ? json_decode((string) $form['schema_json'], true) : null;
    $optionLabels = FormSchema::searchOptionLabels($schema);

    $rows = DB::run('SELECT id, json_payload FROM submissions_cache WHERE form_id = ?', [$form['id']])->fetchAll();
    foreach ($rows as $row) {
        $payload = json_decode((string) $row['json_payload'], true);
        if (!is_array($payload)) continue;
        DB::run(
            'UPDATE submissions_cache SET search_text = ? WHERE id = ?',
            [SubmissionSearch::textFor($payload, $optionLabels), $row['id']]
        );
        $n++;
    }
}

echo "search_text recalculado en $n envío(s)" . ($formId ? " (form $formId)" : '') . ".\n";
