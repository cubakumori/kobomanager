<?php
/**
 * POST /api/v1/admin/forms/{id}/sync   (solo admin)
 * Trae a la caché los envíos nuevos/modificados de UN formulario desde Kobo
 * (a diferencia de /admin/forms/sync, que descubre los formularios de una cuenta).
 */

$admin  = Auth::requireAdmin();
$formId = (int) Request::param('id');

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run(
    'SELECT f.id, f.kobo_asset_uid, a.server_url, a.api_token
     FROM forms f
     JOIN kobo_accounts a ON a.id = f.kobo_account_id
     WHERE f.id = ? AND a.active = 1',
    [$formId]
)->fetch();

if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

try {
    $client = new KoboClient($form['server_url'], TokenVault::decrypt($form['api_token']));
    $count  = SubmissionSync::syncForm($formId, $form['kobo_asset_uid'], $client);
    Audit::log($admin['id'], 'sync_submissions', $formId, null, ['count' => $count]);
    ErrorResponse::ok(['form_id' => $formId, 'submissions' => $count]);
} catch (KoboException $e) {
    ErrorResponse::send($e->errorCode, $e->getMessage());
}
