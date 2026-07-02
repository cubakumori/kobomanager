<?php
/**
 * GET /api/v1/public/share/{token}/stats   (PÚBLICO, sin sesión)
 * Estadísticas del enlace, con su filtro de filas y ocultado de columnas
 * aplicados. NO expone el estado de revisión interno (`by_status` y la mezcla de
 * revisión de `by_team` se omiten con includeReview=false), coherente con el resto
 * de la vista pública de solo lectura.
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$link  = ShareLink::requireAccess($token, 'stats');

$formId     = (int) $link['form_id'];
$schemaRaw  = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
$scope      = ShareLink::rule($link);
$fieldScope = FieldScope::ruleForLink($link);

// Micro-caché en disco SOLO para esta vista pública: el endpoint es anónimo
// (con throttle por IP aparte) y Stats::compute recorre todos los envíos en
// alcance, así que un enlace popular no debe recalcular en cada visita. Un
// archivo por enlace; el fingerprint cubre la configuración del enlace, el
// esquema y `last_synced_at` (una sincronización invalida sola) y el TTL acota
// la frescura de los cambios de revisión hechos en la app entre syncs. La vista
// interna autenticada NO se cachea (sus usuarios esperan ver su revisión al momento).
$locale      = Settings::defaultLocale();
$cacheFile   = sys_get_temp_dir() . '/km_share_stats_' . (int) $link['id'] . '.json';
$fingerprint = md5(json_encode($link, JSON_UNESCAPED_UNICODE) . '|' . $locale);
$cacheTtl    = 300;

if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $cacheTtl) {
    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    if (is_array($cached) && ($cached['fp'] ?? null) === $fingerprint && is_array($cached['payload'] ?? null)) {
        ErrorResponse::ok($cached['payload']);
    }
}

// Alcance FIJO del enlace: equipos (restricción de fila adicional, vía $extraScope) y
// estado de revisión. `includeReview=false` mantiene oculto el desglose `by_status`,
// pero el conjunto sí se acota a «solo aprobados» si el enlace lo fija.
$stats = Stats::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $locale, false,
    $link['stats_team_field'] ?: null, $link['stats_enumerator_field'] ?: null,
    ShareLink::statusScope($link), null, ShareLink::teamRule($link)
);

$payload = array_merge([
    'form'              => ['name' => $link['form_name']],
    'deployment_status' => $link['deployment_status'] ?? null,
], $stats);

@file_put_contents(
    $cacheFile,
    json_encode(['fp' => $fingerprint, 'payload' => $payload], JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

ErrorResponse::ok($payload);
