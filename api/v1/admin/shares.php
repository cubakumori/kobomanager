<?php
/**
 * /api/v1/admin/shares   (solo admin)
 *
 *   GET  → lista de enlaces de solo lectura (con su formulario y estado).
 *   POST { form_id, label?, expose_list?, expose_detail?, expose_map?,
 *          row_filter?, password?, expires_at? }
 *        → crea un enlace y devuelve su token.
 *
 * El subconjunto de envíos se restringe con `row_filter` (mismo formato que el
 * scoping por filas de los permisos). La contraseña es opcional según la política
 * global `share_password_policy` ('off'|'optional'|'required').
 */

$admin  = Auth::requireAdmin();
$method = Request::method();

if ($method === 'GET') {
    $rows = DB::run(
        "SELECT sl.id, sl.token, sl.form_id, f.name AS form_name, sl.label,
                sl.expose_list, sl.expose_detail, sl.expose_map, sl.expose_attachments, sl.row_filter,
                (sl.password_hash IS NOT NULL) AS has_password,
                sl.expires_at, sl.revoked_at, sl.last_accessed_at, sl.access_count,
                sl.created_at, u.name AS created_by_name
         FROM share_links sl
         JOIN forms f ON f.id = sl.form_id
         LEFT JOIN users u ON u.id = sl.created_by
         ORDER BY sl.created_at DESC"
    )->fetchAll();

    $now   = time();
    $items = array_map(fn($r) => [
        'id'              => (int) $r['id'],
        'token'           => $r['token'],
        'form'            => ['id' => (int) $r['form_id'], 'name' => $r['form_name']],
        'label'           => $r['label'],
        'expose_list'       => (bool) $r['expose_list'],
        'expose_detail'     => (bool) $r['expose_detail'],
        'expose_map'        => (bool) $r['expose_map'],
        'expose_attachments'=> (bool) $r['expose_attachments'],
        'row_filter'      => RowScope::normalize($r['row_filter'] ? json_decode($r['row_filter'], true) : null),
        'has_password'    => (bool) $r['has_password'],
        'expires_at'      => $r['expires_at'],
        'revoked_at'      => $r['revoked_at'],
        'expired'         => $r['expires_at'] !== null && strtotime((string) $r['expires_at']) < $now,
        'last_accessed_at'=> $r['last_accessed_at'],
        'access_count'    => (int) $r['access_count'],
        'created_at'      => $r['created_at'],
        'created_by'      => $r['created_by_name'],
    ], $rows);

    ErrorResponse::ok([
        'items'              => $items,
        'password_policy'    => Settings::sharePasswordPolicy(),
        'attachments_policy' => Settings::shareAttachmentsPolicy(),
    ]);
}

if ($method === 'POST') {
    $body   = Request::json();
    $formId = (int) ($body['form_id'] ?? 0);
    if (!$formId) {
        ErrorResponse::send('VALIDATION_ERROR', 'Falta form_id');
    }
    $form = DB::run('SELECT id FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
    if (!$form) {
        ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
    }

    // Qué expone el enlace (al menos uno; la lista es lo razonable por defecto).
    $exposeList   = !empty($body['expose_list']) ? 1 : 0;
    $exposeDetail = !empty($body['expose_detail']) ? 1 : 0;
    $exposeMap    = !empty($body['expose_map']) ? 1 : 0;
    if (!$exposeList && !$exposeDetail && !$exposeMap) {
        ErrorResponse::send('VALIDATION_ERROR', 'El enlace debe exponer al menos una vista');
    }

    // Filtro por filas (scoping): canónico o NULL.
    $rule       = RowScope::normalize($body['row_filter'] ?? null);
    $filterJson = $rule ? json_encode($rule, JSON_UNESCAPED_UNICODE) : null;

    // Contraseña según política global.
    $policy   = Settings::sharePasswordPolicy();
    $password = isset($body['password']) ? (string) $body['password'] : '';
    $hash     = null;
    if ($policy === 'off') {
        $password = '';
    }
    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    } elseif ($policy === 'required') {
        ErrorResponse::send('VALIDATION_ERROR', 'Este servidor exige contraseña en los enlaces');
    }

    // Adjuntos: doble capa. Solo si la política global lo permite Y el enlace
    // tiene contraseña (los adjuntos suelen contener PII sensible).
    $exposeAttachments = 0;
    if (!empty($body['expose_attachments'])) {
        if (Settings::shareAttachmentsPolicy() !== 'require_password') {
            ErrorResponse::send('VALIDATION_ERROR', 'Este servidor no permite exponer adjuntos en enlaces');
        }
        if ($hash === null) {
            ErrorResponse::send('VALIDATION_ERROR', 'Exponer adjuntos requiere proteger el enlace con contraseña');
        }
        $exposeAttachments = 1;
    }

    // Caducidad opcional (YYYY-MM-DD o datetime). En blanco → sin caducidad.
    $expiresAt = null;
    $rawExp    = trim((string) ($body['expires_at'] ?? ''));
    if ($rawExp !== '') {
        $ts = strtotime($rawExp);
        if ($ts === false) {
            ErrorResponse::send('VALIDATION_ERROR', 'Fecha de caducidad no válida');
        }
        if ($ts < time()) {
            ErrorResponse::send('VALIDATION_ERROR', 'La caducidad debe estar en el futuro');
        }
        $expiresAt = date('Y-m-d H:i:s', $ts);
    }

    $label = trim((string) ($body['label'] ?? ''));
    $token = ShareLink::generateToken();

    DB::run(
        'INSERT INTO share_links
            (token, form_id, created_by, label, expose_list, expose_detail, expose_map,
             expose_attachments, row_filter, password_hash, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $token, $formId, $admin['id'], $label !== '' ? $label : null,
            $exposeList, $exposeDetail, $exposeMap, $exposeAttachments, $filterJson, $hash, $expiresAt,
        ]
    );

    $id = (int) DB::conn()->lastInsertId();
    Audit::log($admin['id'], 'share_create', $formId, null, [
        'share_id' => $id, 'exposes' => compact('exposeList', 'exposeDetail', 'exposeMap', 'exposeAttachments'),
        'has_password' => $hash !== null, 'expires_at' => $expiresAt,
    ]);

    ErrorResponse::ok(['id' => $id, 'token' => $token], 201);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
