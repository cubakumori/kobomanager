<?php
/**
 * POST /api/v1/forms/{id}/sync   (usuario con can_view sobre el formulario)
 * Trae a la caché los envíos de UN formulario. ?full=1 / {full:true} → completo.
 *
 * Para viewers requiere que el admin haya habilitado la acción correspondiente:
 *   - incremental → viewer_can_update
 *   - completo    → viewer_can_resync
 * Los admin pueden siempre. Reutiliza SubmissionSync (igual que el endpoint admin).
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

Auth::requireForm($user, $formId, 'view');

$full = !empty($_GET['full']) || !empty(Request::json()['full']);

// Confirmación humana del VACIADO cuando Kobo devuelve 0 envíos vivos con caché
// poblada (ver la guardia anti-vaciado de SubmissionSync). Mismo permiso que el
// sync que la ofreció; el backend re-verifica contra Kobo en esta misma pasada.
$confirmWipe = !empty(Request::json()['confirm_wipe']);

if ($user['role'] !== 'admin') {
    $acts = Settings::viewerActions();
    if (!$acts[$full ? 'resync' : 'update']) {
        ErrorResponse::send('AUTH_INSUFFICIENT_PERMISSIONS');
    }
}

$form = DB::run(
    'SELECT f.id, f.kobo_asset_uid, a.server_url, a.api_token
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
    $res    = SubmissionSync::syncForm($formId, $form['kobo_asset_uid'], $client, $full, $confirmWipe);
    Audit::log($user['id'], 'sync_submissions', $formId, null, $res + ['full' => $full, 'via' => 'viewer']);
    if (!empty($res['wiped'])) {
        Audit::log($user['id'], 'cache_wipe', $formId, null, ['removed' => $res['removed'], 'via' => 'viewer']);
    }
    ErrorResponse::ok([
        'form_id'     => $formId,
        'submissions' => $res['upserted'],
        'removed'     => $res['removed'],
        'full'        => $full,
        'wipe_available' => !empty($res['wipe_available']),
        'cached'      => $res['cached'] ?? 0,
        'wiped'       => !empty($res['wiped']),
    ]);
} catch (KoboException $e) {
    ErrorResponse::send($e->errorCode, $e->getMessage());
}
