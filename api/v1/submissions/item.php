<?php
/**
 * /api/v1/submissions/{id}   ({id} = submission_uid)
 *   GET → detalle del envío desde caché + historial de revisiones. Registra 'view'.
 *   PUT → edita campos del envío: escribe en Kobo y, si tiene éxito, actualiza la caché.
 *         Body: { data: { campo: valor, ... } }   (requiere can_edit)
 */

$user = Auth::require();
$uid  = (string) Request::param('id');
$method = Request::method();

$sub = DB::run(
    'SELECT sc.id, sc.submission_uid, sc.json_payload, sc.submitted_at, sc.last_synced_at,
            f.id AS form_id, f.name AS form_name, f.kobo_asset_uid, f.kobo_account_id, f.schema_json, f.deployment_status
     FROM submissions_cache sc
     JOIN forms f ON f.id = sc.form_id
     WHERE sc.submission_uid = ?',
    [$uid]
)->fetch();

if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}
$formId = (int) $sub['form_id'];

// Scoping por filas: con filtro activo, un envío fuera de alcance se comporta como
// inexistente (404), sea cual sea la capacidad. Se comprueba tras requireForm.
$scopeRule    = RowScope::ruleForUser($user, $formId);
$scopePayload = json_decode($sub['json_payload'], true) ?: [];

// Permisos por columna: campos ocultos a este usuario en este formulario.
$fieldScope = FieldScope::ruleForUser($user, $formId);
$schemaRaw  = $sub['schema_json'] ? json_decode($sub['schema_json'], true) : null;

// ---------- GET: detalle ----------
if ($method === 'GET') {
    Auth::requireForm($user, $formId, 'view');
    if (!RowScope::matches($scopeRule, $scopePayload)) {
        ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
    }

    $reviews = DB::run(
        'SELECT r.id, r.status, r.comment, r.created_at, u.name AS user_name
         FROM submission_reviews r
         JOIN users u ON u.id = r.user_id
         WHERE r.submission_uid = ?
         ORDER BY r.id DESC',
        [$uid]
    )->fetchAll();

    Audit::log($user['id'], 'view', $formId, $uid);

    // Recorta los campos ocultos ANTES de derivar adjuntos/geo/derivados y de devolver `data`.
    $payload = FieldScope::apply($fieldScope, json_decode($sub['json_payload'], true) ?: [], $schemaRaw);

    // Etiquetas legibles: esquema del formulario resuelto al idioma del usuario
    // (sin las etiquetas de campos ocultos).
    $resolved = FieldScope::applySchema($fieldScope, FormSchema::resolve($schemaRaw, $user['locale']));

    // Adjuntos (fotos/audio/archivos): se descargan vía el proxy autenticado del
    // backend, nunca con la download_url cruda de Kobo (que exige token).
    $attachments = Attachments::forPayload($payload);

    // Envío anterior/siguiente, en el mismo orden que la lista (submitted_at DESC, id DESC).
    // "Siguiente" = el inmediatamente más abajo (más antiguo); "anterior" = más arriba (más nuevo).
    $curTime = $sub['submitted_at'];
    $curId   = (int) $sub['id'];
    [$navSql, $navP] = RowScope::sqlCondition($scopeRule, 'json_payload');
    $next = DB::run(
        "SELECT submission_uid FROM submissions_cache
         WHERE form_id = ? AND (submitted_at < ? OR (submitted_at = ? AND id < ?)) AND $navSql
         ORDER BY submitted_at DESC, id DESC LIMIT 1",
        array_merge([$formId, $curTime, $curTime, $curId], $navP)
    )->fetch();
    $prev = DB::run(
        "SELECT submission_uid FROM submissions_cache
         WHERE form_id = ? AND (submitted_at > ? OR (submitted_at = ? AND id > ?)) AND $navSql
         ORDER BY submitted_at ASC, id ASC LIMIT 1",
        array_merge([$formId, $curTime, $curTime, $curId], $navP)
    )->fetch();

    ErrorResponse::ok([
        'submission_uid' => $sub['submission_uid'],
        'form'           => ['id' => $formId, 'name' => $sub['form_name'], 'deployment_status' => $sub['deployment_status'] ?? null],
        'prev'           => $prev['submission_uid'] ?? null,
        'next'           => $next['submission_uid'] ?? null,
        'submitted_at'   => $sub['submitted_at'],
        'last_synced_at' => $sub['last_synced_at'],
        'data'           => $payload,
        'review_status'  => $reviews[0]['status'] ?? 'pending',
        'reviews'        => $reviews,
        'can_edit'       => Auth::canForm($user, $formId, 'edit'),
        'can_validate'   => Auth::canForm($user, $formId, 'validate'),
        'readonly_fields' => FieldScope::readonlyFields($fieldScope), // visibles pero no editables

        'label_mode'     => Settings::labelMode(),
        'field_truncate' => Settings::fieldTruncate(),
        'schema'         => $resolved,
        'attachments'    => $attachments,
        'geo'            => Geo::features($payload, $schemaRaw, $resolved['labels']),
        'derived'        => Derived::compute($payload, $schemaRaw, $sub['submitted_at']),
    ]);
}

// ---------- PUT: edición ----------
if ($method === 'PUT') {
    Auth::requireForm($user, $formId, 'edit');
    if (!RowScope::matches($scopeRule, $scopePayload)) {
        ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
    }

    $data = Request::json()['data'] ?? null;
    if (!is_array($data) || $data === []) {
        ErrorResponse::send('VALIDATION_ERROR', 'Faltan campos a actualizar (data)');
    }
    // No se permite tocar metadatos de Kobo (campos que empiezan por _).
    foreach (array_keys($data) as $k) {
        if (str_starts_with((string) $k, '_')) {
            ErrorResponse::send('VALIDATION_ERROR', "No se puede editar el metadato: $k");
        }
        // No se puede editar un campo que el usuario no ve (oculto por columna).
        if (FieldScope::isHidden($fieldScope, (string) $k)) {
            ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
        }
        // Campo visible pero de solo lectura para este usuario: edición rechazada
        // explícita (nada se escribe a medias en Kobo).
        if (FieldScope::isReadonly($fieldScope, (string) $k)) {
            ErrorResponse::send('VALIDATION_ERROR', "Campo de solo lectura: $k");
        }
    }

    $payload = json_decode($sub['json_payload'], true) ?: [];
    $koboId  = $payload['_id'] ?? null;
    if (!$koboId) {
        ErrorResponse::send('INTERNAL_ERROR', 'El envío en caché no tiene _id de Kobo');
    }

    // Cuenta + token para escribir en Kobo.
    $acc = DB::run(
        'SELECT server_url, api_token FROM kobo_accounts WHERE id = ?',
        [$sub['kobo_account_id']]
    )->fetch();
    if (!$acc) {
        ErrorResponse::send('KOBO_ACCOUNT_DISABLED', 'La cuenta Kobo no existe');
    }

    // Valores anteriores (para auditoría).
    $before = [];
    foreach ($data as $k => $v) {
        $before[$k] = $payload[$k] ?? null;
    }

    // 1) Escribir en Kobo (lanza KoboException si falla).
    //    Una edición en Kobo crea una versión nueva con un _uuid NUEVO (el _id
    //    numérico se conserva); editSubmission devuelve ese _uuid resultante.
    $client = new KoboClient($acc['server_url'], TokenVault::decrypt($acc['api_token']));
    try {
        $newUuid = $client->editSubmission($sub['kobo_asset_uid'], (int) $koboId, $data);
    } catch (KoboException $e) {
        ErrorResponse::send($e->errorCode, $e->getMessage());
    }

    // 2) Solo si Kobo aceptó, actualizar la caché.
    foreach ($data as $k => $v) {
        $payload[$k] = $v;
    }

    // Si el _uuid cambió, migramos la clave de caché y arrastramos el historial de
    // revisiones (indexado por submission_uid = _uuid) para no perderlo en el
    // próximo resync `full` (que reconcilia por _uuid y borraría la fila antigua).
    $changedUuid = ($newUuid !== '' && $newUuid !== $uid);
    if ($changedUuid) {
        $payload['_uuid'] = $newUuid;
    }

    $conn = DB::conn();
    $conn->beginTransaction();
    try {
        DB::run(
            'UPDATE submissions_cache SET submission_uid = ?, json_payload = ?, search_text = ? WHERE id = ?',
            [
                $changedUuid ? $newUuid : $uid,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                SubmissionSearch::textFor($payload, FormSchema::searchOptionLabels($schemaRaw)),
                $sub['id'],
            ]
        );
        if ($changedUuid) {
            DB::run(
                'UPDATE submission_reviews SET submission_uid = ? WHERE submission_uid = ?',
                [$newUuid, $uid]
            );
        }
        $conn->commit();
    } catch (\Throwable $e) {
        $conn->rollBack();
        // Kobo ya aceptó el cambio; informamos del fallo de caché para que el usuario
        // resincronice (la edición en Kobo es real).
        ErrorResponse::send('INTERNAL_ERROR', 'La edición se guardó en Kobo pero falló la actualización de la caché local');
    }

    Audit::log($user['id'], 'edit', $formId, $uid, ['before' => $before, 'after' => $data, 'new_uid' => $changedUuid ? $newUuid : null]);

    ErrorResponse::ok([
        'data'           => $payload,
        'submission_uid' => $changedUuid ? $newUuid : $uid,
    ]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
