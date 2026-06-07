<?php
/**
 * GET /api/v1/public/share/{token}/submissions/{uid}   (PÚBLICO, sin sesión)
 * Detalle de un envío a través del enlace. Aplica el filtro de filas del enlace:
 * un envío fuera de alcance o de otro formulario responde 404. No expone
 * adjuntos (no hay proxy público) ni el historial de revisiones interno.
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$uid   = (string) Request::param('uid');
$link  = ShareLink::requireAccess($token, 'detail');

$formId = (int) $link['form_id'];

$sub = DB::run(
    'SELECT id, submission_uid, json_payload, submitted_at
     FROM submissions_cache WHERE submission_uid = ? AND form_id = ?',
    [$uid, $formId]
)->fetch();
if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}

$payload = json_decode($sub['json_payload'], true) ?: [];

$scope = ShareLink::rule($link);
if (!RowScope::matches($scope, $payload)) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}

$schema   = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
$resolved = FormSchema::resolve($schema, Settings::defaultLocale());

// Envío anterior/siguiente dentro del alcance del enlace (mismo orden que la lista).
$curTime = $sub['submitted_at'];
$curId   = (int) $sub['id'];
[$navSql, $navP] = RowScope::sqlCondition($scope, 'json_payload');
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

// Datos sin metadatos de adjuntos (no se exponen archivos a través del enlace).
unset($payload['_attachments']);

ErrorResponse::ok([
    'submission_uid' => $sub['submission_uid'],
    'form'           => ['name' => $link['form_name']],
    'submitted_at'   => $sub['submitted_at'],
    'prev'           => $prev['submission_uid'] ?? null,
    'next'           => $next['submission_uid'] ?? null,
    'data'           => $payload,
    'label_mode'     => Settings::labelMode(),
    'field_truncate' => Settings::fieldTruncate(),
    'schema'         => $resolved,
    'geo'            => Geo::features($payload, $schema, $resolved['labels']),
]);
