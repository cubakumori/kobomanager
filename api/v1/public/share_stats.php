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
// El nombre del archivo incorpora un HMAC con JWT_SECRET: en hosting compartido el
// temp del sistema puede ser legible/enumerable por otros tenants, y un nombre
// predecible les dejaría localizar (y con permisos laxos, leer) agregados
// semi-públicos. El chmod 0600 tras escribir cierra la lectura ajena.
$locale      = Settings::defaultLocale();
$cacheName   = hash_hmac('sha256', 'share_stats|' . (int) $link['id'], JWT_SECRET);
$cacheFile   = sys_get_temp_dir() . '/km_' . $cacheName . '.json';
$fingerprint = md5(json_encode($link, JSON_UNESCAPED_UNICODE) . '|' . $locale . '|v2');
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
$statusScope = ShareLink::statusScope($link);
$stats = Stats::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $locale, false,
    $link['stats_team_field'] ?: null, $link['stats_enumerator_field'] ?: null,
    $statusScope, null, ShareLink::teamRule($link)
);

// La tarjeta «Total» de Stats::compute es a propósito el conjunto COMPLETO en alcance
// (en la vista interna las tarjetas de cabecera son el selector de estado). Aquí no hay
// selector: si el enlace fija un estado, el total debe ser el acotado — un enlace «solo
// aprobados» no debe revelar cuántos envíos sin aprobar existen.
if ($statusScope !== null) {
    [$rowSql, $rowP]       = ShareLink::rowSql($link, 'json_payload');
    [$statusSql, $statusP] = ValidationStatus::statusFilterSql($statusScope);
    $stats['total'] = (int) DB::run(
        "SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ? AND $rowSql AND $statusSql",
        array_merge([$formId], $rowP, $statusP)
    )->fetch()['c'];
}

$payload = array_merge([
    'form'              => ['name' => $link['form_name']],
    'deployment_status' => $link['deployment_status'] ?? null,
], $stats);

@file_put_contents(
    $cacheFile,
    json_encode(['fp' => $fingerprint, 'payload' => $payload], JSON_UNESCAPED_UNICODE),
    LOCK_EX
);
@chmod($cacheFile, 0600);

ErrorResponse::ok($payload);
