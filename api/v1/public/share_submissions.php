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
// Alcance por filas del enlace = row_filter + equipos.
[$scopeSql, $scopeP] = ShareLink::rowSql($link, 'sc.json_payload');
// Alcance por estado de revisión (p. ej. solo aprobados), por submission_uid.
[$stSql, $stP]       = ValidationStatus::latestFilterSql(ShareLink::statusScope($link), 'sc.submission_uid');

// Ocultado de columnas del enlace.
$schema     = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
$fieldScope = FieldScope::ruleForLink($link);

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;
$search  = trim((string) ($_GET['search'] ?? ''));

$where  = 'WHERE sc.form_id = ? AND ' . $scopeSql . ' AND ' . $stSql;
$params = array_merge([$formId], $scopeP, $stP);
if ($search !== '') {
    [$searchSql, $searchParams] = $fieldScope !== null
        ? SubmissionSearch::clauseVisible('sc', $search, FieldScope::visiblePaths($fieldScope, $schema))
        : SubmissionSearch::clause('sc', $search);
    $where  .= ' AND ' . $searchSql;
    $params  = array_merge($params, $searchParams);
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
    'data'           => FieldScope::apply($fieldScope, json_decode($r['json_payload'], true) ?: [], $schema),
], $rows);

$locale = Settings::defaultLocale();

ErrorResponse::ok([
    'form'          => ['name' => $link['form_name']],
    'items'         => $items,
    'page'          => $page,
    'per_page'      => $perPage,
    'total'         => $total,
    'label_mode'    => Settings::labelMode(),
    'field_truncate' => Settings::fieldTruncate(),
    'schema'        => FieldScope::applySchema($fieldScope, FormSchema::resolve($schema, $locale)),
    'expose_detail' => (bool) $link['expose_detail'],
    'expose_map'    => (bool) $link['expose_map'],
]);
