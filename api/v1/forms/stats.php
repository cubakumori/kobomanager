<?php
/**
 * GET /api/v1/forms/{id}/stats   (requiere can_view)
 * Estadísticas calculadas sobre submissions_cache:
 *   - total de envíos
 *   - envíos por día (fecha de envío)
 *   - distribución por estado de revisión (última revisión de cada envío)
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

// Scoping por filas: el viewer puede tener un filtro que limita qué envíos cuenta.
$scope               = RowScope::ruleForUser($user, $formId);
[$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

$total = (int) DB::run(
    "SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ? AND $scopeSql",
    array_merge([$formId], $scopeP)
)->fetch()['c'];

// Envíos por día.
$byDay = DB::run(
    "SELECT DATE(submitted_at) AS day, COUNT(*) AS count
     FROM submissions_cache
     WHERE form_id = ? AND submitted_at IS NOT NULL AND $scopeSql
     GROUP BY DATE(submitted_at)
     ORDER BY day",
    array_merge([$formId], $scopeP)
)->fetchAll();
$byDay = array_map(fn($r) => ['date' => $r['day'], 'count' => (int) $r['count']], $byDay);

// Distribución por estado de revisión: la revisión más reciente de cada envío de este formulario.
$reviewed = DB::run(
    "SELECT r.status, COUNT(*) AS count
     FROM submission_reviews r
     JOIN (
        SELECT submission_uid, MAX(id) AS max_id FROM submission_reviews GROUP BY submission_uid
     ) latest ON latest.max_id = r.id
     JOIN submissions_cache sc ON sc.submission_uid = r.submission_uid AND sc.form_id = ?
        AND $scopeSql
     GROUP BY r.status",
    array_merge([$formId], $scopeP)
)->fetchAll();

$byStatus = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$reviewedTotal = 0;
foreach ($reviewed as $r) {
    if (isset($byStatus[$r['status']])) {
        $byStatus[$r['status']] = (int) $r['count'];
        $reviewedTotal += (int) $r['count'];
    }
}
// Los envíos sin revisión cuentan como 'pending'.
$byStatus['pending'] += $total - $reviewedTotal;

ErrorResponse::ok([
    'form'      => ['id' => (int) $form['id'], 'name' => $form['name']],
    'total'     => $total,
    'by_day'    => $byDay,
    'by_status' => $byStatus,
]);
