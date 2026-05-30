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

$total = (int) DB::run(
    'SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ?',
    [$formId]
)->fetch()['c'];

// Envíos por día.
$byDay = DB::run(
    'SELECT DATE(submitted_at) AS day, COUNT(*) AS count
     FROM submissions_cache
     WHERE form_id = ? AND submitted_at IS NOT NULL
     GROUP BY DATE(submitted_at)
     ORDER BY day',
    [$formId]
)->fetchAll();
$byDay = array_map(fn($r) => ['date' => $r['day'], 'count' => (int) $r['count']], $byDay);

// Distribución por estado de revisión: la revisión más reciente de cada envío de este formulario.
$reviewed = DB::run(
    'SELECT r.status, COUNT(*) AS count
     FROM submission_reviews r
     JOIN (
        SELECT submission_uid, MAX(id) AS max_id FROM submission_reviews GROUP BY submission_uid
     ) latest ON latest.max_id = r.id
     JOIN submissions_cache sc ON sc.submission_uid = r.submission_uid AND sc.form_id = ?
     GROUP BY r.status',
    [$formId]
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
