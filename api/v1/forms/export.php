<?php
/**
 * GET /api/v1/forms/{id}/export   (requiere can_view)
 * Exporta los envíos del formulario a CSV (UTF-8 con BOM, abre bien en Excel).
 * Respeta el scoping por filas y los mismos filtros que la lista (search, review).
 * No usa el envoltorio JSON: emite el CSV directamente como descarga.
 *
 * Columnas: submitted_at + estado de revisión + un campo por pregunta. Las
 * cabeceras y los valores siguen el modo de etiquetas global (labels|raw); en
 * modo labels, las opciones (select_one/select_multiple) se muestran con su texto.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name, schema_json FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$scope               = RowScope::ruleForUser($user, $formId);
[$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'sc.json_payload');

$search = trim((string) ($_GET['search'] ?? ''));
$review = (string) ($_GET['review'] ?? '');

$join = 'LEFT JOIN (
        SELECT r.submission_uid, r.status
        FROM submission_reviews r
        JOIN (SELECT submission_uid, MAX(id) AS max_id FROM submission_reviews GROUP BY submission_uid) m
          ON m.max_id = r.id
    ) lr ON lr.submission_uid = sc.submission_uid';

$where  = 'WHERE sc.form_id = ? AND ' . $scopeSql;
$params = array_merge([$formId], $scopeP);
if ($search !== '') {
    [$searchSql, $searchParams] = SubmissionSearch::clause('sc', $search);
    $where  .= ' AND ' . $searchSql;
    $params  = array_merge($params, $searchParams);
}
if (in_array($review, ['pending', 'approved', 'rejected'], true)) {
    $where    .= ' AND COALESCE(lr.status, \'pending\') = ?';
    $params[]  = $review;
}

$rows = DB::run(
    "SELECT sc.json_payload, sc.submitted_at, COALESCE(lr.status, 'pending') AS review_status
     FROM submissions_cache sc
     $join
     $where
     ORDER BY sc.submitted_at DESC, sc.id DESC",
    $params
)->fetchAll();

// Esquema resuelto al idioma del usuario (etiquetas y opciones legibles).
$schemaRaw = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$resolved  = FormSchema::resolve($schemaRaw, $user['locale']);
$labelsOn  = Settings::labelMode() === 'labels' && $resolved;
$labels    = $resolved['labels'] ?? [];
$options   = $resolved['options'] ?? [];
$multi     = array_flip($resolved['multi'] ?? []);

$leaf = static fn(string $k): string => (($p = strrpos($k, '/')) === false) ? $k : substr($k, $p + 1);

// Columnas de datos: orden del esquema primero, luego cualquier clave extra vista
// en los envíos (sin metadatos de Kobo, que empiezan por «_»).
$columns = [];
foreach (array_keys($schemaRaw['fields'] ?? []) as $k) {
    if (!str_starts_with($k, '_')) $columns[$k] = true;
}
foreach ($rows as $r) {
    foreach (json_decode($r['json_payload'], true) ?: [] as $k => $_v) {
        if (!str_starts_with((string) $k, '_')) $columns[$k] = true;
    }
}
$columns = array_keys($columns);

// Cabecera de una columna de datos (etiqueta o clave cruda).
$header = static function (string $k) use ($labelsOn, $labels, $leaf): string {
    if (!$labelsOn) return $k;
    return $labels[$k] ?? $labels[$leaf($k)] ?? $k;
};

// Valor mostrable de una celda (mapea códigos de opción a su etiqueta).
$cell = static function (string $k, $v) use ($labelsOn, $options, $multi, $leaf): string {
    $raw = (!is_scalar($v) && $v !== null) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? '');
    if (!$labelsOn) return $raw;
    $opt = $options[$k] ?? $options[$leaf($k)] ?? null;
    if (!$opt) return $raw;
    if (isset($multi[$k]) || isset($multi[$leaf($k)])) {
        $codes = preg_split('/\s+/', trim((string) ($v ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $codes ? implode(', ', array_map(fn($c) => $opt[$c] ?? $c, $codes)) : $raw;
    }
    $code = (string) ($v ?? '');
    return array_key_exists($code, $opt) ? $opt[$code] : $raw;
};

// Cabeceras de las dos columnas de metadatos, en el idioma del usuario.
$metaHeaders = $user['locale'] === 'en'
    ? ['submitted' => 'Submitted', 'review' => 'Review']
    : ['submitted' => 'Enviado', 'review' => 'Revisión'];
$reviewWords = $user['locale'] === 'en'
    ? ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']
    : ['pending' => 'Pendiente', 'approved' => 'Aprobado', 'rejected' => 'Rechazado'];

// Columnas calculadas (las mismas que la tabla): se computan con lib/Derived,
// idéntico al detalle y la lista. Van al final, tras las columnas de datos.
$derivedHeaders = $user['locale'] === 'en'
    ? ['Duration (s)', 'Has attachments', 'Has geo']
    : ['Duración (s)', 'Tiene adjuntos', 'Tiene geo'];
$yesNo = $user['locale'] === 'en' ? ['yes' => 'Yes', 'no' => 'No'] : ['yes' => 'Sí', 'no' => 'No'];

// --- Emitir CSV ---
$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $form['name']) ?: 'export';
$filename = $safeName . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

// $escape = '' → CSV estándar (comillas dobladas, sin escape con barra). Además, en
// PHP 8.4+ el parámetro $escape debe pasarse explícitamente (su omisión está obsoleta).
fputcsv($out, array_merge([$metaHeaders['submitted'], $metaHeaders['review']], array_map($header, $columns), $derivedHeaders), ',', '"', '');
foreach ($rows as $r) {
    $data = json_decode($r['json_payload'], true) ?: [];
    $line = [$r['submitted_at'], $reviewWords[$r['review_status']] ?? $r['review_status']];
    foreach ($columns as $k) {
        $line[] = $cell($k, $data[$k] ?? null);
    }
    $d = Derived::compute($data, $schemaRaw, $r['submitted_at']);
    $line[] = $d['duration_s'] ?? '';
    $line[] = $d['has_attachments'] ? $yesNo['yes'] : $yesNo['no'];
    $line[] = $d['has_geo'] ? $yesNo['yes'] : $yesNo['no'];
    fputcsv($out, $line, ',', '"', '');
}
fclose($out);
exit;
