<?php
/**
 * GET /api/v1/forms/{id}/quality/export   (requiere can_view)
 * Exporta el drill-down de infracciones del Control de calidad: UNA FILA POR
 * ENVÍO MARCADO (uid, equipo, encuestador, tiempos, banderas, estado de revisión),
 * para llevar a la reunión con el equipo de campo. Formato según `?format=` (csv
 * por defecto o xlsx), igual que el export de envíos.
 *
 * Reutiliza lib/Quality::compute() EXACTAMENTE como la página /quality (mismo
 * RowScope, FieldScope, alcance por estado `qc_scope` y umbrales del formulario),
 * así que exporta las mismas infracciones que se ven en pantalla. No usa el
 * envoltorio JSON: emite el archivo directamente como descarga.
 *
 * Cabeceras y valores (banderas, estado) van en el idioma del usuario; en modo
 * etiquetas los nombres de equipo/encuestador ya vienen resueltos por Quality.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run(
    'SELECT id, name, schema_json, stats_team_field, stats_enumerator_field,
            qc_min_duration, qc_max_duration, qc_min_gap, qc_dup_min_answers
     FROM forms WHERE id = ? AND active = 1',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$format = strtolower((string) ($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'xlsx'], true)) $format = 'csv';

$scope      = RowScope::ruleForUser($user, $formId);
$fieldScope = FieldScope::ruleForUser($user, $formId);
$schemaRaw  = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;

// Mismo alcance por estado que la página (ajuste global «Control de calidad: alcance»),
// incluido el `?scope=` transitorio del toggle: la vista lo propaga al exportar para
// que el archivo contenga exactamente las infracciones que se ven en pantalla.
$qcScope = Settings::qcScope();
$scopeParam = (string) ($_GET['scope'] ?? '');
if (in_array($scopeParam, Settings::VALID_QC_SCOPE, true)) {
    $qcScope = $scopeParam;
}
$statuses = $qcScope === 'all' ? null : ['pending', 'on_hold'];

$quality = Quality::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
    $form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null,
    $form['qc_min_duration'] !== null ? (int) $form['qc_min_duration'] : null,
    $form['qc_max_duration'] !== null ? (int) $form['qc_max_duration'] : null,
    $form['qc_min_gap'] !== null ? (int) $form['qc_min_gap'] : null,
    $statuses,
    $form['qc_dup_min_answers'] !== null ? (int) $form['qc_dup_min_answers'] : null
);

$en = $user['locale'] === 'en';

// Cabeceras de columna (idioma del usuario). Orden: quién → qué encuesta → cuándo →
// cuánto/hueco → por qué → estado.
$headerRow = $en
    ? ['Team', 'Enumerator', 'UID', 'Submitted', 'Start', 'End', 'Duration (s)', 'Gap (s)', 'Flags', 'Review']
    : ['Equipo', 'Encuestador', 'UID', 'Enviado', 'Inicio', 'Fin', 'Duración (s)', 'Hueco (s)', 'Banderas', 'Revisión'];

// Etiqueta legible de cada bandera (forma singular; mismas palabras que la UI). El
// orden de emisión sigue Quality::FLAGS, el orden canónico.
$flagWords = $en
    ? ['short' => 'Short', 'long' => 'Long', 'short_gap' => 'Short gap', 'overlap' => 'Overlapping', 'overlap_long' => 'Overlap w/ long record', 'duplicate' => 'Duplicate', 'gps' => 'Pinned GPS']
    : ['short' => 'Corta', 'long' => 'Larga', 'short_gap' => 'Hueco corto', 'overlap' => 'Solapada', 'overlap_long' => 'Solape con registro largo', 'duplicate' => 'Duplicada', 'gps' => 'GPS clavado'];
$reviewWords = $en
    ? ['pending' => 'Pending', 'approved' => 'Approved', 'on_hold' => 'On hold', 'rejected' => 'Rejected']
    : ['pending' => 'Pendiente', 'approved' => 'Aprobado', 'on_hold' => 'En espera', 'rejected' => 'Rechazado'];

// Aplana teams[] → enumerators[] → violations[] en filas de export, respetando el
// orden ya calculado por Quality (equipos y encuestadores por nº de infracciones).
// $numeric=true devuelve duración/hueco como int|null para que en xlsx sean celdas
// numéricas de verdad; en CSV da igual (todo es texto).
$rows = static function () use ($quality, $flagWords, $reviewWords): iterable {
    foreach ($quality['teams'] as $team) {
        foreach ($team['enumerators'] as $enum) {
            foreach ($enum['violations'] as $v) {
                $flags = array_map(fn($f) => $flagWords[$f] ?? $f, $v['flags']);
                yield [
                    'team'   => $team['name'],
                    'enum'   => $enum['name'],
                    'uid'    => $v['uid'],
                    'sub'    => $v['submitted_at'],
                    'start'  => $v['start_at'],   // ya formateado local por Derived
                    'end'    => $v['end_at'],
                    'dur'    => $v['duration_s'], // int|null (segundos)
                    'gap'    => $v['gap_s'],      // int|null (segundos; negativo = solape)
                    'flags'  => implode(', ', $flags),
                    'review' => $reviewWords[$v['review_status']] ?? $v['review_status'],
                ];
            }
        }
    }
};

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $form['name']) ?: 'export';
$fileBase = $safeName . '_qc_' . date('Ymd');

if ($format === 'xlsx') {
    // --- Emitir XLSX (columnas nativas; duración/hueco como celdas numéricas) ---
    require_once __DIR__ . '/../../lib/XlsxWriter.php';
    try {
        $xlsx = new XlsxWriter();
    } catch (Throwable $e) {
        ErrorResponse::send('INTERNAL_ERROR', 'No se pudo generar el archivo Excel');
    }
    $xlsx->addRow($headerRow);
    foreach ($rows() as $r) {
        $xlsx->addRow([
            $r['team'], $r['enum'], $r['uid'], $r['sub'], $r['start'], $r['end'],
            $r['dur'] === null ? '' : (int) $r['dur'],
            $r['gap'] === null ? '' : (int) $r['gap'],
            $r['flags'], $r['review'],
        ]);
    }
    $xlsx->stream($fileBase . '.xlsx');
    exit;
}

// --- Emitir CSV ---
// Neutraliza la inyección de fórmulas CSV (los nombres de equipo/encuestador vienen
// de datos de terceros): una celda que empiece por = + - @ TAB CR se fuerza a texto.
$csvSafe = static function ($v): string {
    $s = (string) ($v ?? '');
    return ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $s : $s;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

// $escape = '' → CSV estándar (comillas dobladas, sin escape con barra). En PHP 8.4+
// el parámetro $escape debe pasarse explícitamente.
fputcsv($out, array_map($csvSafe, $headerRow), ',', '"', '');
foreach ($rows() as $r) {
    fputcsv($out, array_map($csvSafe, [
        $r['team'], $r['enum'], $r['uid'], $r['sub'], $r['start'], $r['end'],
        $r['dur'] === null ? '' : (string) $r['dur'],
        $r['gap'] === null ? '' : (string) $r['gap'],
        $r['flags'], $r['review'],
    ]), ',', '"', '');
}
fclose($out);
exit;
