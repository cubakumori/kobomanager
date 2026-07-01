<?php
/**
 * /api/v1/admin/shares   (solo admin)
 *
 *   GET  → lista de enlaces de solo lectura (con su formulario y estado).
 *   POST { form_id, label?, expose_list?, expose_detail?, expose_map?, expose_stats?,
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
                sl.expose_list, sl.expose_detail, sl.expose_map, sl.expose_stats, sl.expose_attachments,
                sl.row_filter, sl.field_filter, sl.team_filter, sl.stats_status,
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
        'expose_stats'      => (bool) $r['expose_stats'],
        'expose_attachments'=> (bool) $r['expose_attachments'],
        'row_filter'      => RowScope::normalize($r['row_filter'] ? json_decode($r['row_filter'], true) : null),
        'field_filter'    => FieldScope::normalize($r['field_filter'] ? json_decode($r['field_filter'], true) : null),
        'team_filter'     => $r['team_filter'] ? json_decode($r['team_filter'], true) : null,
        'stats_status'    => $r['stats_status'] ?: 'all',
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
    $form = DB::run('SELECT id, stats_team_field FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
    if (!$form) {
        ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
    }

    // Ajustes comunes (qué expone, columnas, equipos, estado, contraseña, caducidad).
    $settings = ShareLink::parseSettings($body, $form);

    // Filtro por filas (scoping): canónico o NULL.
    $rule       = RowScope::normalize($body['row_filter'] ?? null);
    $filterJson = $rule ? json_encode($rule, JSON_UNESCAPED_UNICODE) : null;

    $label = trim((string) ($body['label'] ?? ''));
    $token = ShareLink::generateToken();

    DB::run(
        'INSERT INTO share_links
            (token, form_id, created_by, label, expose_list, expose_detail, expose_map, expose_stats,
             expose_attachments, row_filter, field_filter, team_filter, stats_status, password_hash, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $token, $formId, $admin['id'], $label !== '' ? $label : null,
            $settings['expose_list'], $settings['expose_detail'], $settings['expose_map'], $settings['expose_stats'],
            $settings['expose_attachments'], $filterJson, $settings['field_filter'],
            $settings['team_filter'], $settings['stats_status'], $settings['password_hash'], $settings['expires_at'],
        ]
    );

    $id = (int) DB::conn()->lastInsertId();
    Audit::log($admin['id'], 'share_create', $formId, null, [
        'share_id' => $id,
        'exposes'  => [
            'exposeList'        => $settings['expose_list'], 'exposeDetail' => $settings['expose_detail'],
            'exposeMap'         => $settings['expose_map'],  'exposeStats'  => $settings['expose_stats'],
            'exposeAttachments' => $settings['expose_attachments'],
        ],
        'has_password' => $settings['password_hash'] !== null, 'expires_at' => $settings['expires_at'],
        'stats_status' => $settings['stats_status'] ?? 'all', 'team_filter' => $settings['team_filter'] !== null,
    ]);

    ErrorResponse::ok(['id' => $id, 'token' => $token], 201);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
