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
            f.id AS form_id, f.name AS form_name, f.kobo_asset_uid, f.kobo_account_id, f.schema_json
     FROM submissions_cache sc
     JOIN forms f ON f.id = sc.form_id
     WHERE sc.submission_uid = ?',
    [$uid]
)->fetch();

if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}
$formId = (int) $sub['form_id'];

// ---------- GET: detalle ----------
if ($method === 'GET') {
    Auth::requireForm($user, $formId, 'view');

    $reviews = DB::run(
        'SELECT r.id, r.status, r.comment, r.created_at, u.name AS user_name
         FROM submission_reviews r
         JOIN users u ON u.id = r.user_id
         WHERE r.submission_uid = ?
         ORDER BY r.id DESC',
        [$uid]
    )->fetchAll();

    Audit::log($user['id'], 'view', $formId, $uid);

    $payload = json_decode($sub['json_payload'], true) ?: [];

    // Etiquetas legibles: esquema del formulario resuelto al idioma del usuario.
    $schema   = $sub['schema_json'] ? json_decode($sub['schema_json'], true) : null;
    $resolved = FormSchema::resolve($schema, $user['locale']);

    // Adjuntos (fotos/audio/archivos): se descargan vía el proxy autenticado del
    // backend, nunca con la download_url cruda de Kobo (que exige token).
    $attachments = [];
    foreach (($payload['_attachments'] ?? []) as $a) {
        $attUid = $a['uid'] ?? null;
        if (!$attUid) continue;
        $mime = (string) ($a['mimetype'] ?? '');
        $attachments[] = [
            'uid'      => $attUid,
            'name'     => $a['media_file_basename'] ?? basename((string) ($a['filename'] ?? $attUid)),
            'mimetype' => $mime ?: null,
            'field'    => $a['question_xpath'] ?? null,
            'kind'     => str_starts_with($mime, 'image/') ? 'image'
                       : (str_starts_with($mime, 'audio/') ? 'audio'
                       : (str_starts_with($mime, 'video/') ? 'video' : 'file')),
        ];
    }

    ErrorResponse::ok([
        'submission_uid' => $sub['submission_uid'],
        'form'           => ['id' => $formId, 'name' => $sub['form_name']],
        'submitted_at'   => $sub['submitted_at'],
        'last_synced_at' => $sub['last_synced_at'],
        'data'           => $payload,
        'review_status'  => $reviews[0]['status'] ?? 'pending',
        'reviews'        => $reviews,
        'can_edit'       => Auth::canForm($user, $formId, 'edit'),
        'can_validate'   => Auth::canForm($user, $formId, 'validate'),
        'label_mode'     => Settings::labelMode(),
        'schema'         => $resolved,
        'attachments'    => $attachments,
        'geo'            => Geo::features($payload, $schema, $resolved['labels']),
    ]);
}

// ---------- PUT: edición ----------
if ($method === 'PUT') {
    Auth::requireForm($user, $formId, 'edit');

    $data = Request::json()['data'] ?? null;
    if (!is_array($data) || $data === []) {
        ErrorResponse::send('VALIDATION_ERROR', 'Faltan campos a actualizar (data)');
    }
    // No se permite tocar metadatos de Kobo (campos que empiezan por _).
    foreach (array_keys($data) as $k) {
        if (str_starts_with((string) $k, '_')) {
            ErrorResponse::send('VALIDATION_ERROR', "No se puede editar el metadato: $k");
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
    $client = new KoboClient($acc['server_url'], TokenVault::decrypt($acc['api_token']));
    try {
        $client->editSubmission($sub['kobo_asset_uid'], (int) $koboId, $data);
    } catch (KoboException $e) {
        ErrorResponse::send($e->errorCode, $e->getMessage());
    }

    // 2) Solo si Kobo aceptó, actualizar la caché.
    foreach ($data as $k => $v) {
        $payload[$k] = $v;
    }
    DB::run(
        'UPDATE submissions_cache SET json_payload = ? WHERE id = ?',
        [json_encode($payload, JSON_UNESCAPED_UNICODE), $sub['id']]
    );

    Audit::log($user['id'], 'edit', $formId, $uid, ['before' => $before, 'after' => $data]);

    ErrorResponse::ok(['data' => $payload]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
