<?php
/**
 * GET /api/v1/forms/{id}/submissions   (requiere can_view)
 * Lista paginada de envíos desde submissions_cache.
 * Query:
 *   page (1+), per_page (1-100), search (texto libre sobre el JSON),
 *   review (pending|approved|rejected) → filtra por estado de revisión más reciente,
 *   sort (date_desc|date_asc) → orden por fecha de envío (por defecto, más recientes).
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

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;
$search  = trim((string) ($_GET['search'] ?? ''));
$review  = (string) ($_GET['review'] ?? '');
$sortDir = (($_GET['sort'] ?? 'date_desc') === 'date_asc') ? 'ASC' : 'DESC';

// Estado de revisión más reciente por envío, para mostrar y para poder filtrar.
$join = 'LEFT JOIN (
        SELECT r.submission_uid, r.status
        FROM submission_reviews r
        JOIN (SELECT submission_uid, MAX(id) AS max_id FROM submission_reviews GROUP BY submission_uid) m
          ON m.max_id = r.id
    ) lr ON lr.submission_uid = sc.submission_uid';

$where  = 'WHERE sc.form_id = ? AND ' . $scopeSql;
$params = array_merge([$formId], $scopeP);
if ($search !== '') {
    $where    .= ' AND CAST(sc.json_payload AS CHAR) LIKE ?';
    $params[]  = '%' . $search . '%';
}
if (in_array($review, ['pending', 'approved', 'rejected'], true)) {
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
     ORDER BY sc.submitted_at $sortDir, sc.id $sortDir
     LIMIT $perPage OFFSET $offset",
    $params
)->fetchAll();

$items = array_map(fn($r) => [
    'id'             => (int) $r['id'],
    'submission_uid' => $r['submission_uid'],
    'submitted_at'   => $r['submitted_at'],
    'review_status'  => $r['review_status'],
    'data'           => json_decode($r['json_payload'], true),
], $rows);

// Etiquetas legibles: esquema del formulario resuelto al idioma del usuario.
$schema = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;

// ¿Algún envío tiene coordenadas? (para habilitar/deshabilitar la vista de mapa).
$geoConds  = ['JSON_TYPE(JSON_EXTRACT(json_payload, \'$._geolocation[0]\')) IN (\'DOUBLE\',\'INTEGER\',\'DECIMAL\')'];
$geoParams = [$formId];
foreach (Geo::geoFieldPaths($schema) as $gp) {
    $geoConds[]  = 'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(json_payload, ?)), \'\') <> \'\'';
    $geoParams[] = '$."' . str_replace(['"', '\\'], '', $gp) . '"';
}
[$scopeSqlNa, $scopePNa] = RowScope::sqlCondition($scope, 'json_payload');
$hasGeo = (bool) DB::run(
    'SELECT 1 FROM submissions_cache WHERE form_id = ? AND (' . implode(' OR ', $geoConds) . ')'
        . ' AND ' . $scopeSqlNa . ' LIMIT 1',
    array_merge($geoParams, $scopePNa)
)->fetch();

ErrorResponse::ok([
    'form'       => ['id' => (int) $form['id'], 'name' => $form['name']],
    'items'      => $items,
    'page'       => $page,
    'per_page'   => $perPage,
    'total'      => $total,
    'label_mode' => Settings::labelMode(),
    'schema'     => FormSchema::resolve($schema, $user['locale']),
    'has_geo'    => $hasGeo,
]);
