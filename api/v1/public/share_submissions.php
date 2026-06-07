<?php
/**
 * GET /api/v1/public/share/{token}/submissions   (PÚBLICO, sin sesión)
 * Lista paginada de envíos del enlace, con el filtro de filas del propio enlace
 * aplicado. No expone estado de revisión interno (es solo lectura pública).
 * Query: page (1+), per_page (1-100), search (texto libre sobre el JSON).
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$link  = ShareLink::requireAccess($token, 'list');

$formId              = (int) $link['form_id'];
$scope               = ShareLink::rule($link);
[$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'sc.json_payload');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;
$search  = trim((string) ($_GET['search'] ?? ''));

$where  = 'WHERE sc.form_id = ? AND ' . $scopeSql;
$params = array_merge([$formId], $scopeP);
if ($search !== '') {
    $where    .= ' AND CAST(sc.json_payload AS CHAR) LIKE ?';
    $params[]  = '%' . $search . '%';
}

$total = (int) DB::run("SELECT COUNT(*) AS c FROM submissions_cache sc $where", $params)->fetch()['c'];

$rows = DB::run(
    "SELECT sc.submission_uid, sc.json_payload, sc.submitted_at
     FROM submissions_cache sc
     $where
     ORDER BY sc.submitted_at DESC, sc.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
)->fetchAll();

$items = array_map(fn($r) => [
    'submission_uid' => $r['submission_uid'],
    'submitted_at'   => $r['submitted_at'],
    'data'           => json_decode($r['json_payload'], true),
], $rows);

$schema = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
$locale = Settings::defaultLocale();

ErrorResponse::ok([
    'form'          => ['name' => $link['form_name']],
    'items'         => $items,
    'page'          => $page,
    'per_page'      => $perPage,
    'total'         => $total,
    'label_mode'    => Settings::labelMode(),
    'schema'        => FormSchema::resolve($schema, $locale),
    'expose_detail' => (bool) $link['expose_detail'],
    'expose_map'    => (bool) $link['expose_map'],
]);
