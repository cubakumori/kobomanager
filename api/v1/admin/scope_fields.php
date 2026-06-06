<?php
/**
 * GET /api/v1/admin/forms/{id}/scope-fields   (solo admin)
 *
 * Ayuda a configurar el filtro por filas de un viewer:
 *   - sin parámetros → campos del formulario (clave, etiqueta, tipo, opciones)
 *     derivados del esquema XLSForm cacheado. Las etiquetas van en el idioma del admin.
 *   - ?values=CLAVE → valores DISTINCT existentes en la caché para ese campo
 *     (sugerencias para campos de texto / metadatos como _submitted_by).
 *
 * Los metadatos de Kobo (p. ej. _submitted_by) los añade el frontend al selector.
 */

$admin  = Auth::requireAdmin();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name, schema_json FROM forms WHERE id = ?', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

$schema   = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$resolved = FormSchema::resolve($schema, $admin['locale']);

// ?values=CLAVE → valores distintos para ese campo (acotado).
$valuesFor = isset($_GET['values']) ? (string) $_GET['values'] : '';
if ($valuesFor !== '') {
    $path = '$."' . str_replace(['\\', '"'], '', $valuesFor) . '"';
    // Excluir campos ausentes (SQL NULL) y valores JSON null (JSON_UNQUOTE los
    // devolvería como la cadena "null"). Solo valores reales como sugerencia.
    $rows = DB::run(
        'SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(json_payload, ?)) AS v
         FROM submissions_cache
         WHERE form_id = ?
           AND JSON_EXTRACT(json_payload, ?) IS NOT NULL
           AND JSON_TYPE(JSON_EXTRACT(json_payload, ?)) <> \'NULL\'
         ORDER BY v
         LIMIT 50',
        [$path, $formId, $path, $path]
    )->fetchAll();
    $values = [];
    foreach ($rows as $r) {
        $v = $r['v'];
        if ($v !== null && $v !== '') $values[] = $v;
    }
    ErrorResponse::ok(['values' => $values]);
}

// Lista de campos filtrables desde el esquema (ruta completa como clave del envío).
$fields = [];
foreach (($schema['fields'] ?? []) as $full => $f) {
    $options = [];
    if (isset($resolved['options'][$full])) {
        foreach ($resolved['options'][$full] as $val => $lbl) {
            $options[] = ['value' => (string) $val, 'label' => $lbl];
        }
    }
    $fields[] = [
        'key'     => $full,
        'label'   => $resolved['labels'][$full] ?? $full,
        'type'    => $f['type'] ?? '',
        'multi'   => !empty($f['multi']),   // select_multiple: no soportado por el filtro (v1)
        'options' => $options,
    ];
}

ErrorResponse::ok([
    'form'   => ['id' => (int) $form['id'], 'name' => $form['name']],
    'fields' => $fields,
]);
