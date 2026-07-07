<?php
/**
 * GET /api/v1/forms/{id}/submissions   (requiere can_view)
 * Lista paginada de envíos desde submissions_cache.
 * Query:
 *   page (1+), per_page (1-100), search (texto libre sobre el JSON),
 *   review (pending|approved|on_hold|rejected) → filtra por estado de revisión más reciente,
 *   sort → orden GLOBAL de la tabla: date_asc|date_desc (por defecto), las columnas
 *     calculadas (duration|attachments|geo con sufijo _asc/_desc) o una columna de
 *     datos del formulario: `field:<clave>_asc|_desc` (cabeceras clicables),
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

$form = DB::run('SELECT id, name, schema_json, deployment_status FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
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
if (str_starts_with($sortKey, 'field:')) {
    // Orden por una COLUMNA DE DATOS del formulario (cabecera clicable). La clave debe
    // ser una pregunta del esquema y no estar oculta para el usuario (ordenar por un
    // campo oculto filtraría sus valores); si no cumple —p. ej. una vista guardada que
    // referencia un campo ocultado o eliminado después— se cae al orden por fecha en
    // vez de romper la tabla. Vacíos SIEMPRE al final; los tipos numéricos se ordenan
    // con CAST («9» < «10», no orden lexicográfico). El orden es por el VALOR
    // almacenado (códigos en los select), no por la etiqueta mostrada.
    $fieldKey = substr($sortKey, 6);
    $known    = is_array($schema['fields'] ?? null) && array_key_exists($fieldKey, $schema['fields']);
    if ($known && !FieldScope::isHidden($fieldScope, $fieldKey)) {
        $ex   = "JSON_UNQUOTE(JSON_EXTRACT(sc.json_payload, ?))";
        $type = (string) ($schema['fields'][$fieldKey]['type'] ?? '');
        $val  = in_array($type, ['integer', 'decimal', 'range'], true)
            ? "CAST($ex AS DECIMAL(30,10))"
            : $ex;
        $orderBy     = "(COALESCE($ex, '') = '') ASC, $val $dir, sc.submitted_at DESC, sc.id DESC";
        $orderParams = [RowScope::jsonPath($fieldKey), RowScope::jsonPath($fieldKey)];
    } else {
        $orderBy = 'sc.submitted_at DESC, sc.id DESC';
    }
} else switch ($sortKey) {
    case 'duration':
        // Columna materializada por el sync (end − start con las claves meta del
        // esquema, ver Derived::cacheColumns): sin parsear JSON por fila.
        $orderBy = "sc.duration_s $dir, sc.submitted_at DESC, sc.id DESC";
        break;
    case 'attachments':
        $orderBy = "sc.att_count $dir, sc.submitted_at DESC, sc.id DESC";
        break;
    case 'geo':
        // Sin campos geo ocultos, la columna materializada equivale al geoExpr;
        // con alguno oculto se conserva la expresión JSON (respeta el FieldScope).
        if (!$anyGeoHidden) {
            $orderBy = "sc.has_geo $dir, sc.submitted_at DESC, sc.id DESC";
        } else {
            $orderBy     = "$geoExpr $dir, sc.submitted_at DESC, sc.id DESC";
            $orderParams = $geoExprParams;
        }
        break;
    default: // 'date'
        $orderBy = "sc.submitted_at $dir, sc.id $dir";
        break;
}

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
    // Estado vigente desnormalizado (submissions_cache.review_status, indexado por
    // formulario): sin materializar el log de revisiones en cada petición.
    $where    .= ' AND sc.review_status = ?';
    $params[]  = $review;
}

$total = (int) DB::run("SELECT COUNT(*) AS c FROM submissions_cache sc $where", $params)
    ->fetch()['c'];

$rows = DB::run(
    "SELECT sc.id, sc.submission_uid, sc.json_payload, sc.submitted_at, sc.review_status
     FROM submissions_cache sc
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
// Sin campos geo ocultos usa la columna materializada (sin parsear JSON: antes, un
// formulario SIN geo escaneaba el payload de todas sus filas en cada carga); con
// alguno oculto, la expresión JSON que respeta el FieldScope.
$hasGeo = false;
if (!$anyGeoHidden) {
    $hasGeo = (bool) DB::run(
        "SELECT 1 FROM submissions_cache sc WHERE sc.form_id = ? AND sc.has_geo = 1 AND $scopeSql LIMIT 1",
        array_merge([$formId], $scopeP)
    )->fetch();
} elseif ($geoExpr !== '0') {
    $hasGeo = (bool) DB::run(
        "SELECT 1 FROM submissions_cache sc WHERE sc.form_id = ? AND $geoExpr AND $scopeSql LIMIT 1",
        array_merge([$formId], $geoExprParams, $scopeP)
    )->fetch();
}

ErrorResponse::ok([
    'form'       => ['id' => (int) $form['id'], 'name' => $form['name'], 'deployment_status' => $form['deployment_status'] ?? null],
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
