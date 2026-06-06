<?php
/**
 * GET /api/v1/forms/{id}/enketo   (usuario con can_view sobre el formulario)
 * Devuelve el enlace público de Enketo. Para viewers requiere además que el
 * admin haya habilitado la acción (ajuste viewer_can_enketo); los admin siempre.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

Auth::requireForm($user, $formId, 'view');
if ($user['role'] !== 'admin' && !Settings::viewerActions()['enketo']) {
    ErrorResponse::send('AUTH_INSUFFICIENT_PERMISSIONS');
}

$form = DB::run(
    'SELECT f.kobo_asset_uid, a.server_url, a.api_token
     FROM forms f
     JOIN kobo_accounts a ON a.id = f.kobo_account_id
     WHERE f.id = ? AND f.active = 1 AND a.active = 1',
    [$formId]
)->fetch();

if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

try {
    $client = new KoboClient($form['server_url'], TokenVault::decrypt($form['api_token']));
    $url    = $client->getEnketoUrl($form['kobo_asset_uid']);
} catch (KoboException $e) {
    ErrorResponse::send($e->errorCode, $e->getMessage());
}

if (!$url) {
    ErrorResponse::send('KOBO_FORM_NOT_FOUND', 'El formulario no tiene enlace público (¿está desplegado?)');
}

ErrorResponse::ok(['url' => $url]);
