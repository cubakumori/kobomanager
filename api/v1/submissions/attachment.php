<?php
/**
 * GET /api/v1/submissions/{id}/attachments/{attId}
 *   {id}    = submission_uid
 *   {attId} = uid del adjunto (att…) dentro de _attachments
 *
 * Descarga (proxy) un adjunto de Kobo usando el token de la cuenta y lo stremea
 * al navegador. Así el frontend nunca maneja la download_url cruda (que exige el
 * token). Requiere can_view sobre el formulario.
 */

$user   = Auth::require();
$uid    = (string) Request::param('id');
$attId  = (string) Request::param('attId');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$sub = DB::run(
    'SELECT sc.json_payload, f.id AS form_id, f.kobo_account_id
     FROM submissions_cache sc
     JOIN forms f ON f.id = sc.form_id
     WHERE sc.submission_uid = ?',
    [$uid]
)->fetch();

if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}
$formId = (int) $sub['form_id'];
Auth::requireForm($user, $formId, 'view');

// Localizar el adjunto por su uid dentro del envío cacheado.
$payload = json_decode($sub['json_payload'], true) ?: [];
$att = null;
foreach (($payload['_attachments'] ?? []) as $a) {
    if (($a['uid'] ?? null) === $attId) {
        $att = $a;
        break;
    }
}
if (!$att || empty($att['download_url'])) {
    ErrorResponse::send('NOT_FOUND', 'Adjunto no encontrado');
}

// Cuenta + token para descargar de Kobo.
$acc = DB::run(
    'SELECT server_url, api_token FROM kobo_accounts WHERE id = ?',
    [$sub['kobo_account_id']]
)->fetch();
if (!$acc) {
    ErrorResponse::send('KOBO_ACCOUNT_DISABLED', 'La cuenta Kobo no existe');
}

try {
    $client = new KoboClient($acc['server_url'], TokenVault::decrypt($acc['api_token']));
    $file   = $client->getAttachment($att['download_url']);
} catch (KoboException $e) {
    ErrorResponse::send($e->errorCode, $e->getMessage());
}

Audit::log($user['id'], 'download_attachment', $formId, $uid, ['attachment' => $attId]);

// Streamear el archivo. Content-Disposition inline para que el navegador lo
// muestre (imagen/audio/vídeo) o lo descargue si no sabe representarlo.
$name = $att['media_file_basename'] ?? basename((string) ($att['filename'] ?? $attId));
header('Content-Type: ' . ($file['mimetype'] ?: ($att['mimetype'] ?? 'application/octet-stream')));
header('Content-Length: ' . strlen($file['body']));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Cache-Control: private, max-age=300');
echo $file['body'];
exit;
