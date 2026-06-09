<?php
/**
 * GET /api/v1/submissions/{id}/history   ({id} = submission_uid actual)
 *
 * Historial de ediciones de un envío. Como cada edición en Kobo crea una versión con un
 * `_uuid` NUEVO (y el audit guarda `detail.new_uid`), el linaje se reconstruye recorriendo
 * la cadena HACIA ATRÁS: la edición cuyo `new_uid` = uid actual tiene como `submission_uid`
 * el uid anterior; se repite hasta el origen.
 *
 * Requiere can_edit (admins incluidos). Un envío fuera del scoping por filas → 404.
 */

$user = Auth::require();
$uid  = (string) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$sub = DB::run(
    'SELECT sc.form_id, sc.json_payload, f.schema_json
     FROM submissions_cache sc JOIN forms f ON f.id = sc.form_id
     WHERE sc.submission_uid = ?',
    [$uid]
)->fetch();
if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}
$formId = (int) $sub['form_id'];
Auth::requireForm($user, $formId, 'edit');

// Fuera de alcance del scoping por filas → inexistente.
$scopeRule = RowScope::ruleForUser($user, $formId);
if (!RowScope::matches($scopeRule, json_decode($sub['json_payload'], true) ?: [])) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}

// Etiquetas legibles (campo y opciones) en el idioma del usuario; respeta campos ocultos.
$schemaRaw  = $sub['schema_json'] ? json_decode($sub['schema_json'], true) : null;
$resolved   = FormSchema::resolve($schemaRaw, $user['locale']);
$labels     = $resolved['labels'] ?? [];
$options    = $resolved['options'] ?? [];
$fieldScope = FieldScope::ruleForUser($user, $formId);

// Convierte un valor crudo (código o códigos separados por espacios) a su etiqueta.
$display = function ($field, $value) use ($options) {
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (isset($options[$field])) {
        $parts = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $mapped = array_map(fn($c) => $options[$field][$c] ?? $c, $parts);
        return implode(', ', $mapped);
    }
    return $value;
};

// Recorre la cadena de uuid hacia atrás (tope de seguridad anti-ciclos).
$edits = [];
$cur   = $uid;
$seen  = [];
for ($i = 0; $i < 200 && $cur !== null && !isset($seen[$cur]); $i++) {
    $seen[$cur] = true;
    $row = DB::run(
        "SELECT a.submission_uid AS from_uid, a.created_at, a.detail, u.name AS user_name
         FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.form_id = ? AND a.action = 'edit'
           AND JSON_UNQUOTE(JSON_EXTRACT(a.detail, '$.new_uid')) = ?
         ORDER BY a.id DESC LIMIT 1",
        [$formId, $cur]
    )->fetch();
    if (!$row) {
        break;
    }
    $detail = $row['detail'] ? json_decode($row['detail'], true) : [];
    $before = is_array($detail['before'] ?? null) ? $detail['before'] : [];
    $after  = is_array($detail['after'] ?? null) ? $detail['after'] : [];

    $changes = [];
    foreach ($after as $field => $newVal) {
        if (FieldScope::isHidden($fieldScope, (string) $field)) {
            continue; // no exponer el historial de un campo oculto
        }
        $changes[] = [
            'field' => $field,
            'label' => $labels[$field] ?? $field,
            'from'  => $display($field, $before[$field] ?? null),
            'to'    => $display($field, $newVal),
        ];
    }

    $edits[] = [
        'at'      => $row['created_at'],
        'user'    => $row['user_name'],
        'changes' => $changes,
    ];
    $cur = $row['from_uid']; // predecesor en la cadena
}

ErrorResponse::ok(['edits' => $edits]);
