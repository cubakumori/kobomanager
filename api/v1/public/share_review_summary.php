<?php
/**
 * GET /api/v1/public/share/{token}/review-summary   (PÚBLICO, sin sesión)
 * Resumen del estado de revisión por equipo/encuestador para enlaces que lo
 * exponen (`expose_review_summary`, opt-in por enlace: la vista pública oculta la
 * revisión por defecto). Reutiliza lib/Quality con el alcance por filas del enlace
 * (row_filter + equipos) y su ocultado de columnas, y devuelve SOLO el resumen.
 *
 * El resumen cuenta TODOS los estados de revisión dentro del alcance por filas:
 * mostrar el progreso de la revisión es justamente el propósito del opt-in, así
 * que el alcance por estado del enlace (`stats_status = 'approved'`) NO lo recorta
 * (ese alcance sigue gobernando lista/detalle/mapa/stats/adjuntos).
 *
 * Solo tiene sentido con campo de equipo o encuestador configurado: sin ellos no
 * hay agrupación que mostrar y el endpoint responde NOT_FOUND (mismo gating que
 * la casilla del editor de enlaces).
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$link  = ShareLink::requireAccess($token, 'review_summary');

// Gating EN VIVO por configuración del formulario: si el admin retiró los campos de
// equipo/encuestador después de crear el enlace, el resumen deja de estar disponible.
if (empty($link['stats_team_field']) && empty($link['stats_enumerator_field'])) {
    ErrorResponse::send('NOT_FOUND', 'Recurso no disponible en este enlace');
}

$formId     = (int) $link['form_id'];
$schemaRaw  = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
$scope      = ShareLink::rule($link);
$fieldScope = FieldScope::ruleForLink($link);

// Micro-caché en disco, mismo patrón (y mismas razones) que share_stats.php: endpoint
// anónimo cuyo cálculo recorre todos los envíos en alcance; el fingerprint cubre la
// configuración del enlace + `last_synced_at` y el TTL acota la frescura de los cambios
// de revisión hechos en la app entre syncs. Nombre con HMAC + chmod 0600 por el temp
// compartido de algunos hostings.
$locale      = Settings::defaultLocale();
$cacheName   = hash_hmac('sha256', 'share_review_summary|' . (int) $link['id'], JWT_SECRET);
$cacheFile   = sys_get_temp_dir() . '/km_' . $cacheName . '.json';
$fingerprint = md5(json_encode($link, JSON_UNESCAPED_UNICODE) . '|' . $locale);
$cacheTtl    = 300;

if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $cacheTtl) {
    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    if (is_array($cached) && ($cached['fp'] ?? null) === $fingerprint && is_array($cached['payload'] ?? null)) {
        ErrorResponse::ok($cached['payload']);
    }
}

// Sin umbrales ni señal de duplicados (aquí solo interesa el resumen de revisión) y
// sin alcance por estado (el resumen cuenta todos, ver cabecera). El alcance fijo por
// equipo del enlace entra como restricción adicional, igual que en share_stats.
$quality = Quality::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $locale,
    $link['stats_team_field'] ?: null, $link['stats_enumerator_field'] ?: null,
    null, null, null,
    null,
    null,
    ShareLink::teamRule($link)
);

$payload = [
    'form'             => ['name' => $link['form_name']],
    'review_summary'   => $quality['review_summary'],
    'team_field'       => $quality['team_field'],
    'enumerator_field' => $quality['enumerator_field'],
];

@file_put_contents(
    $cacheFile,
    json_encode(['fp' => $fingerprint, 'payload' => $payload], JSON_UNESCAPED_UNICODE),
    LOCK_EX
);
@chmod($cacheFile, 0600);

ErrorResponse::ok($payload);
