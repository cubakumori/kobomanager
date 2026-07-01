<?php
/**
 * POST /api/v1/admin/shares/bulk   (solo admin)
 *
 * Crea VARIOS enlaces de solo lectura de una vez: uno por cada valor elegido de
 * un campo `select_one` del formulario (el «campo distintivo»). Cada enlace queda
 * fijado a su valor con una condición extra `campo = valor` combinada en Y con el
 * filtro de filas base. Comparte todos los demás ajustes (qué expone, columnas
 * ocultas, equipos, estado, contraseña, caducidad) con el POST de /admin/shares.
 *
 * Body: { form_id, distinctive_field, values:[código,...], label_prefix?,
 *         expose_*, row_filter?, field_filter?, team_filter?, stats_status?,
 *         password?, expires_at? }
 *   → { created:[{id, token, label, value}], count }
 */

$admin = Auth::requireAdmin();
if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

// Tope de enlaces por acción: evita crear cientos por accidente (alineado con el
// LIMIT de sugerencias de scope-fields).
$bulkMax = 50;

$body   = Request::json();
$formId = (int) ($body['form_id'] ?? 0);
if (!$formId) {
    ErrorResponse::send('VALIDATION_ERROR', 'Falta form_id');
}
$form = DB::run(
    'SELECT id, stats_team_field, schema_json FROM forms WHERE id = ? AND active = 1',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

// Campo distintivo: debe ser un select_one del esquema (con opciones, no múltiple).
$distinctive = trim((string) ($body['distinctive_field'] ?? ''));
if ($distinctive === '') {
    ErrorResponse::send('VALIDATION_ERROR', 'Falta el campo distintivo');
}
$schema    = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$resolved  = FormSchema::resolve($schema, $admin['locale']);
$fieldMeta = $schema['fields'][$distinctive] ?? null;
$options   = $resolved['options'][$distinctive] ?? null;   // [valor => etiqueta] o null
if ($fieldMeta === null || !empty($fieldMeta['multi']) || !is_array($options) || !$options) {
    ErrorResponse::send('VALIDATION_ERROR', 'El campo distintivo debe ser de opción única (select_one)');
}

// Valores elegidos: subconjunto de las opciones del campo (se ignoran los ajenos).
$requested = $body['values'] ?? null;
if (!is_array($requested) || !$requested) {
    ErrorResponse::send('VALIDATION_ERROR', 'Selecciona al menos un valor');
}
$valid = [];
foreach ($requested as $v) {
    $v = (string) $v;
    if ($v !== '' && array_key_exists($v, $options) && !in_array($v, $valid, true)) {
        $valid[] = $v;
    }
}
if (!$valid) {
    ErrorResponse::send('VALIDATION_ERROR', 'Ninguno de los valores es válido para el campo');
}
if (count($valid) > $bulkMax) {
    ErrorResponse::send('VALIDATION_ERROR', "Demasiados enlaces de una vez (máximo $bulkMax)");
}

// Filtro base (opcional, se aplica a TODOS los enlaces): canónico. Debe ser
// AND-componible (raíz 'all' o ≤1 grupo) y NO puede usar el campo distintivo (lo
// fija el propio lote).
$base = RowScope::normalize($body['row_filter'] ?? null);
if ($base !== null) {
    if (($base['match'] ?? 'all') === 'any' && count($base['groups'] ?? []) > 1) {
        ErrorResponse::send('VALIDATION_ERROR', 'El filtro de filas base debe combinar sus grupos con Y');
    }
    if (in_array($distinctive, RowScope::fields($base), true)) {
        ErrorResponse::send('VALIDATION_ERROR', 'El filtro de filas base no puede usar el campo distintivo');
    }
}

// Ajustes comunes (los mismos que el POST simple).
$settings = ShareLink::parseSettings($body, $form);
$prefix   = trim((string) ($body['label_prefix'] ?? ''));

// Todo-o-nada: una transacción para los N enlaces.
$created = [];
$conn = DB::conn();
$conn->beginTransaction();
try {
    $stmt = $conn->prepare(
        'INSERT INTO share_links
            (token, form_id, created_by, label, expose_list, expose_detail, expose_map, expose_stats,
             expose_attachments, row_filter, field_filter, team_filter, stats_status, password_hash, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($valid as $value) {
        $optLabel = trim((string) ($options[$value] ?? ''));
        if ($optLabel === '') {
            $optLabel = $value;
        }
        $label = $prefix !== '' ? $prefix . ' ' . $optLabel : $optLabel;
        $rule  = ShareLink::withScopeValue($base, $distinctive, $value);
        $token = ShareLink::generateToken();
        $stmt->execute([
            $token, $formId, $admin['id'], $label,
            $settings['expose_list'], $settings['expose_detail'], $settings['expose_map'], $settings['expose_stats'],
            $settings['expose_attachments'],
            $rule ? json_encode($rule, JSON_UNESCAPED_UNICODE) : null,
            $settings['field_filter'], $settings['team_filter'], $settings['stats_status'],
            $settings['password_hash'], $settings['expires_at'],
        ]);
        $created[] = [
            'id'    => (int) $conn->lastInsertId(),
            'token' => $token,
            'label' => $label,
            'value' => $value,
        ];
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    throw $e;
}

Audit::log($admin['id'], 'share_bulk_create', $formId, null, [
    'field'        => $distinctive,
    'count'        => count($created),
    'has_password' => $settings['password_hash'] !== null,
    'stats_status' => $settings['stats_status'] ?? 'all',
    'team_filter'  => $settings['team_filter'] !== null,
]);

ErrorResponse::ok(['created' => $created, 'count' => count($created)], 201);
