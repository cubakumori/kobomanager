<?php
/**
 * GET /api/v1/public/share/{token}/sample   (PÚBLICO, sin sesión)
 *
 * Panel de cumplimiento de la MUESTRA por equipo del enlace, con su filtro de
 * filas, alcance por equipo y ocultado de columnas aplicados. Opt-in por enlace
 * (`expose_sample`): el hecho/objetivo agregado y el backlog revelan recuentos
 * agregados de revisión (como el resumen de revisión), nunca envíos individuales.
 *
 * El denominador es SIEMPRE el del plan (`forms.sample_denominator`): el toggle
 * transitorio `?denominator=` de la vista interna es una herramienta de trabajo
 * del revisor y aquí no se ofrece (además rompería la clave de la caché).
 *
 * Gating EN VIVO además del flag: si el formulario dejó de tener campo de
 * muestreo configurado, el panel desaparece del enlace (como el resumen de
 * revisión con sus campos de agrupación).
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$link  = ShareLink::requireAccess($token, 'sample');

if (($link['sample_field'] ?? '') === '') {
    ErrorResponse::send('NOT_FOUND', 'Recurso no disponible en este enlace');
}

$formId     = (int) $link['form_id'];
$schemaRaw  = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
$scope      = ShareLink::rule($link);
$fieldScope = FieldScope::ruleForLink($link);

// Micro-caché en disco, calcada de share_stats.php (endpoint anónimo y
// Sample::compute recorre todos los envíos en alcance): un archivo por enlace,
// nombre con HMAC (temp compartido no enumerable), fingerprint que cubre la
// config del enlace + `last_synced_at` + locale, TTL que acota la frescura de
// los cambios de revisión entre syncs, y chmod 0600 tras escribir.
$locale      = Settings::defaultLocale();
$cacheName   = hash_hmac('sha256', 'share_sample|' . (int) $link['id'], JWT_SECRET);
$cacheFile   = sys_get_temp_dir() . '/km_' . $cacheName . '.json';
$fingerprint = md5(json_encode($link, JSON_UNESCAPED_UNICODE) . '|' . $locale);
$cacheTtl    = 300;

if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $cacheTtl) {
    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    if (is_array($cached) && ($cached['fp'] ?? null) === $fingerprint && is_array($cached['payload'] ?? null)) {
        ErrorResponse::ok($cached['payload']);
    }
}

// Alcance FIJO por equipo del enlace como restricción de fila adicional (AND),
// igual que en share_stats. El `stats_status` del enlace NO aplica aquí: el
// denominador del plan ya define qué estados cuentan como «hecho», y el backlog
// (pendientes/en espera) es parte del panel por diseño.
$secondary = array_values(array_filter(
    [$link['sample_field2'] ?? null, $link['sample_field3'] ?? null],
    fn($v) => $v !== null && $v !== ''
));

$sample = Sample::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $locale,
    $link['stats_team_field'] ?: null,
    (string) $link['sample_field'],
    (string) $link['sample_denominator'],
    $secondary,
    ShareLink::teamRule($link),
    null,
    $link['team_group_field'] ?: null
);

$payload = [
    'form'                  => ['name' => $link['form_name']],
    'deployment_status'     => $link['deployment_status'] ?? null,
    'configured'            => true,
    'team_field_configured' => ($link['stats_team_field'] ?? '') !== '',
    'retention_days'        => $link['retention_days'] !== null ? (int) $link['retention_days'] : null,
] + $sample;

@file_put_contents(
    $cacheFile,
    json_encode(['fp' => $fingerprint, 'payload' => $payload], JSON_UNESCAPED_UNICODE),
    LOCK_EX
);
@chmod($cacheFile, 0600);

ErrorResponse::ok($payload);
