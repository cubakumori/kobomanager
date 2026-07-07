<?php
/**
 * GET /api/v1/public/share/{token}/map   (PÚBLICO, sin sesión)
 * Puntos geográficos de los envíos del enlace (con el filtro de filas aplicado).
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$link  = ShareLink::requireAccess($token, 'map');

$formId              = (int) $link['form_id'];
$schema              = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
// Alcance por filas (row_filter + equipos) y por estado de revisión del enlace.
[$scopeSql, $scopeP] = ShareLink::rowSql($link, 'json_payload');
[$stSql, $stP]       = ValidationStatus::statusFilterSql(ShareLink::statusScope($link), 'review_status');

// Ocultado de columnas: un campo geo oculto no aporta su punto al mapa.
$fieldScope = FieldScope::ruleForLink($link);

// Total en alcance (denominador informativo), sin traer las filas.
$total = (int) DB::run(
    "SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ? AND $scopeSql AND $stSql",
    array_merge([$formId], $scopeP, $stP)
)->fetch()['c'];

// Solo filas CON coordenadas (columna materializada) y en streaming, como el mapa
// interno: es un endpoint público con rate limit generoso, no debe poder cargar
// todo el formulario en RAM. FieldScope recorta después los campos geo ocultos.
$points = [];
foreach (DB::stream(
    "SELECT submission_uid, json_payload, submitted_at
     FROM submissions_cache
     WHERE form_id = ? AND has_geo = 1 AND $scopeSql AND $stSql
     ORDER BY submitted_at DESC, id DESC",
    array_merge([$formId], $scopeP, $stP)
) as $r) {
    $payload = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schema);
    $pt = Geo::primaryPoint($payload, $schema);
    if (!$pt) continue;
    $points[] = [
        'submission_uid' => $r['submission_uid'],
        'submitted_at'   => $r['submitted_at'],
        'lat'            => $pt[0],
        'lng'            => $pt[1],
    ];
}

ErrorResponse::ok([
    'form'   => ['name' => $link['form_name']],
    'points' => $points,
    'total'  => $total,
]);
