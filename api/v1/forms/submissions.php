<?php
/**
 * GET /api/v1/forms/{id}/submissions   (requiere can_view)
 * Lista paginada de envíos desde submissions_cache.
 * Query:
 *   page (1+), per_page (1-100), search (texto libre sobre el JSON),
 *   review (pending|approved|on_hold|rejected) → filtra por estado de revisión más reciente,
 *   sort (date_desc|date_asc) → orden por fecha de envío (por defecto, más recientes),
 *   filter (JSON, mismo formato que row_filter) → FILTRO AVANZADO del usuario; solo
 *     RESTRINGE (se combina en AND con el scoping obligatorio) y se rechaza si
 *     referencia campos ocultos para el usuario.
 * Cada envío incluye su estado de revisión más reciente.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name, schema_json FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

// Scoping por filas: el viewer puede tener un filtro que limita qué envíos ve.
$scope               = RowScope::ruleForUser($user, $formId);
[$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'sc.json_payload');

// Permisos por columna: el viewer puede tener campos ocultos en este formulario.
$schema     = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$fieldScope = FieldScope::ruleForUser($user, $formId);

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;
$search  = trim((string) ($_GET['search'] ?? ''));
$review  = (string) ($_GET['review'] ?? '');

// Geo: expresión SQL booleana «¿este envío tiene coordenadas?», respetando los campos
// geo ocultos (un campo geo oculto no cuenta; si se ocultó alguno, tampoco el respaldo
// _geolocation). Se reutiliza para el indicador has_geo y para ordenar por la columna geo.
$geoFields     = Geo::geoFieldPaths($schema);
$visibleGeo    = array_values(array_filter($geoFields, fn($gp) => !FieldScope::isHidden($fieldScope, $gp)));
$anyGeoHidden  = count($visibleGeo) !== count($geoFields);
$geoConds      = [];
$geoExprParams = [];
if (!$anyGeoHidden) {
    $geoConds[] = "JSON_TYPE(JSON_EXTRACT(sc.json_payload, '$._geolocation[0]')) IN ('DOUBLE','INTEGER','DECIMAL')";
}
foreach ($visibleGeo as $gp) {
    $geoConds[]      = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(sc.json_payload, ?)), '') <> ''";
    $geoExprParams[] = RowScope::jsonPath($gp);
}
$geoExpr = $geoConds ? '(' . implode(' OR ', $geoConds) . ')' : '0';

// Orden: por fecha (date) o por una columna CALCULADA (duración, nº de adjuntos, geo),
// expresada como SQL sobre el JSON para que el orden sea GLOBAL (toda la tabla, no solo
// la página). El sufijo _asc/_desc fija la dirección. Los params del ORDER BY van aparte
// (se añaden solo a la consulta de listado, no al COUNT).
$sort = (string) ($_GET['sort'] ?? 'date_desc');
$dir  = str_ends_with($sort, '_asc') ? 'ASC' : 'DESC';
$sortKey = preg_replace('/_(asc|desc)$/', '', $sort);
$orderParams = [];
switch ($sortKey) {
    case 'duration':
        // Duración = end − start (claves meta del esquema, con respaldo de convención).
        // STR_TO_DATE sobre los primeros 19 chars del ISO («YYYY-MM-DDTHH:MM:SS», sin ms
        // ni zona): start y end comparten zona, así que la diferencia es correcta.
        $startKey = $schema['meta']['start'] ?? 'start';
        $endKey   = $schema['meta']['end'] ?? 'end';
        $dt = fn() => "STR_TO_DATE(REPLACE(SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(sc.json_payload, ?)),1,19),'T',' '),'%Y-%m-%d %H:%i:%s')";
        $orderBy     = "TIMESTAMPDIFF(SECOND, {$dt()}, {$dt()}) $dir, sc.submitted_at DESC, sc.id DESC";
        $orderParams = [RowScope::jsonPath($startKey), RowScope::jsonPath($endKey)];
        break;
    case 'attachments':
        $orderBy = "COALESCE(JSON_LENGTH(JSON_EXTRACT(sc.json_payload, '$._attachments')), 0) $dir, sc.submitted_at DESC, sc.id DESC";
        break;
    case 'geo':
        $orderBy     = "$geoExpr $dir, sc.submitted_at DESC, sc.id DESC";
        $orderParams = $geoExprParams;
        break;
    default: // 'date'
        $orderBy = "sc.submitted_at $dir, sc.id $dir";
        break;
}

// Estado de revisión más reciente por envío, para mostrar y para poder filtrar.
$join = 'LEFT JOIN (
        SELECT r.submission_uid, r.status
        FROM submission_reviews r
        JOIN (SELECT submission_uid, MAX(id) AS max_id FROM submission_reviews GROUP BY submission_uid) m
          ON m.max_id = r.id
    ) lr ON lr.submission_uid = sc.submission_uid';

// Filtro avanzado del usuario (mismo formato y motor que row_filter). Solo puede
// RESTRINGIR: se combina en AND con el scoping obligatorio. Si referencia un campo
// oculto se rechaza (filtrar por valor revelaría información del campo).
$advFilter = null;
$rawFilter = trim((string) ($_GET['filter'] ?? ''));
if ($rawFilter !== '') {
    $advFilter = RowScope::normalize(json_decode($rawFilter, true));
    foreach (RowScope::fields($advFilter) as $f) {
        if (FieldScope::isHidden($fieldScope, $f)) {
            ErrorResponse::send('VALIDATION_ERROR', "El filtro usa un campo no disponible: $f");
        }
    }
}
[$advSql, $advP] = RowScope::sqlCondition($advFilter, 'sc.json_payload');

$where  = 'WHERE sc.form_id = ? AND ' . $scopeSql . ' AND ' . $advSql;
$params = array_merge([$formId], $scopeP, $advP);
if ($search !== '') {
    // Con columnas ocultas, la búsqueda solo casa campos visibles (no el índice
    // global, que filtraría que una fila contiene un valor oculto). Si no, FULLTEXT.
    [$searchSql, $searchParams] = $fieldScope !== null
        ? SubmissionSearch::clauseVisible('sc', $search, FieldScope::visiblePaths($fieldScope, $schema))
        : SubmissionSearch::clause('sc', $search);
    $where  .= ' AND ' . $searchSql;
    $params  = array_merge($params, $searchParams);
}
if (in_array($review, ['pending', 'approved', 'on_hold', 'rejected'], true)) {
    $where    .= ' AND COALESCE(lr.status, \'pending\') = ?';
    $params[]  = $review;
}

$total = (int) DB::run("SELECT COUNT(*) AS c FROM submissions_cache sc $join $where", $params)
    ->fetch()['c'];

$rows = DB::run(
    "SELECT sc.id, sc.submission_uid, sc.json_payload, sc.submitted_at,
            COALESCE(lr.status, 'pending') AS review_status
     FROM submissions_cache sc
     $join
     $where
     ORDER BY $orderBy
     LIMIT $perPage OFFSET $offset",
    array_merge($params, $orderParams)
)->fetchAll();

$items = array_map(function ($r) use ($schema, $fieldScope) {
    // Recorta los campos ocultos ANTES de calcular derivados y de devolver `data`.
    $data = FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schema);
    return [
        'id'             => (int) $r['id'],
        'submission_uid' => $r['submission_uid'],
        'submitted_at'   => $r['submitted_at'],
        'review_status'  => $r['review_status'],
        'data'           => $data,
        // Valores calculados (duración, adjuntos, geo…) para columnas opcionales.
        'derived'        => Derived::compute($data, $schema, $r['submitted_at']),
    ];
}, $rows);

// ¿Algún envío tiene coordenadas? (para habilitar/deshabilitar la vista de mapa).
// Reutiliza $geoExpr (misma alias `sc`, ya respeta los campos geo ocultos) calculado
// arriba para no duplicar la lógica.
$hasGeo = false;
if ($geoExpr !== '0') {
    $hasGeo = (bool) DB::run(
        "SELECT 1 FROM submissions_cache sc WHERE sc.form_id = ? AND $geoExpr AND $scopeSql LIMIT 1",
        array_merge([$formId], $geoExprParams, $scopeP)
    )->fetch();
}

ErrorResponse::ok([
    'form'       => ['id' => (int) $form['id'], 'name' => $form['name']],
    'items'      => $items,
    'page'       => $page,
    'per_page'   => $perPage,
    'total'      => $total,
    'label_mode' => Settings::labelMode(),
    'field_truncate' => Settings::fieldTruncate(),
    'schema'     => FieldScope::applySchema($fieldScope, FormSchema::resolve($schema, $user['locale'])),
    'has_geo'    => $hasGeo,
    'can_validate' => Auth::canForm($user, $formId, 'validate'),
]);
