<?php
/**
 * GET /api/v1/forms/{id}/scope-fields   (requiere can_view)
 *
 * Variante para usuarios (no solo admin) de admin/scope_fields.php: alimenta el
 * editor de FILTROS AVANZADOS de la tabla de envíos.
 *   - sin parámetros → campos del formulario (clave, etiqueta, tipo, opciones),
 *     EXCLUYENDO los campos ocultos a este usuario (FieldScope).
 *   - ?values=CLAVE → valores DISTINCT en caché para ese campo, limitados al
 *     alcance por filas del usuario (RowScope) — sin fugas de valores fuera de
 *     alcance ni de campos ocultos.
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

$fieldScope = FieldScope::ruleForUser($user, $formId);
$schema     = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$resolved   = FieldScope::applySchema($fieldScope, FormSchema::resolve($schema, $user['locale']));

// ?values=CLAVE → valores distintos para ese campo dentro del alcance del usuario.
$valuesFor = isset($_GET['values']) ? (string) $_GET['values'] : '';
if ($valuesFor !== '') {
    if (FieldScope::isHidden($fieldScope, $valuesFor)) {
        ErrorResponse::send('NOT_FOUND', 'Campo no encontrado');
    }
    [$scopeSql, $scopeP] = RowScope::sqlCondition(RowScope::ruleForUser($user, $formId), 'json_payload');
    $path = RowScope::jsonPath($valuesFor);
    $rows = DB::run(
        "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(json_payload, ?)) AS v
         FROM submissions_cache
         WHERE form_id = ?
           AND JSON_EXTRACT(json_payload, ?) IS NOT NULL
           AND JSON_TYPE(JSON_EXTRACT(json_payload, ?)) <> 'NULL'
           AND $scopeSql
         ORDER BY v
         LIMIT 50",
        array_merge([$path, $formId, $path, $path], $scopeP)
    )->fetchAll();
    $values = [];
    foreach ($rows as $r) {
        $v = $r['v'];
        if ($v !== null && $v !== '') $values[] = $v;
    }
    ErrorResponse::ok(['values' => $values]);
}

// Lista de campos filtrables (solo los visibles para este usuario).
$fields = [];
foreach (($schema['fields'] ?? []) as $full => $f) {
    if (FieldScope::isHidden($fieldScope, (string) $full)) continue;
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
        'multi'   => !empty($f['multi']),
        'options' => $options,
    ];
}

ErrorResponse::ok([
    'form'   => ['id' => (int) $form['id'], 'name' => $form['name']],
    'fields' => $fields,
]);
