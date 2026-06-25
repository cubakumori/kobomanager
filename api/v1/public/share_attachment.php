<?php
/**
 * GET /api/v1/public/share/{token}/submissions/{uid}/attachments/{attId}
 *   (PÚBLICO, sin sesión)
 *
 * Proxy de un adjunto de Kobo a través de un enlace compartido. Descarga el
 * archivo con el token de la cuenta (que NUNCA sale al navegador) y lo streamea.
 *
 * Sólo funciona si el enlace expone adjuntos (`expose_attachments`); si tiene
 * contraseña, exige un ticket válido. Como un <img>/<audio>/<video> no puede
 * enviar la cabecera X-Share-Ticket, el ticket viaja en `?k=` (lo lee
 * `ShareLink::requireAccess`). Además valida que el envío pertenezca al enlace y
 * esté dentro de su alcance de filas, y que el adjunto pertenezca al envío.
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$uid   = (string) Request::param('uid');
$attId = (string) Request::param('attId');

$link   = ShareLink::requireAccess($token, 'attachments');
$formId = (int) $link['form_id'];

// Alcance por estado de revisión del enlace (p. ej. solo aprobados): fuera de estado → 404.
[$stSql, $stP] = ValidationStatus::latestFilterSql(ShareLink::statusScope($link), 'sc.submission_uid');

$sub = DB::run(
    "SELECT sc.json_payload, f.kobo_account_id
     FROM submissions_cache sc
     JOIN forms f ON f.id = sc.form_id
     WHERE sc.submission_uid = ? AND sc.form_id = ? AND $stSql",
    array_merge([$uid, $formId], $stP)
)->fetch();
if (!$sub) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}

$payload = json_decode($sub['json_payload'], true) ?: [];

// Fuera del alcance de filas del enlace (row_filter + equipos) → como inexistente.
if (!ShareLink::matchesScope($link, $payload)) {
    ErrorResponse::send('NOT_FOUND', 'Envío no encontrado');
}

// El adjunto debe pertenecer a este envío.
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

// Ocultado de columnas del enlace: no se sirve el adjunto de un campo oculto.
if (FieldScope::isHidden(FieldScope::ruleForLink($link), (string) ($att['question_xpath'] ?? ''))) {
    ErrorResponse::send('NOT_FOUND', 'Adjunto no encontrado');
}

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

// Solo se muestra inline el multimedia; el resto se fuerza como descarga. CSP +
// sandbox neutraliza cualquier ejecución de scripts (defensa en profundidad sobre
// el nosniff global), importante en un endpoint PÚBLICO. No se audita ni se
// incrementa access_count (acceso público sin usuario; ver memoria/decisiones P4).
$mime = $file['mimetype'] ?: ($att['mimetype'] ?? 'application/octet-stream');
$name = $att['media_file_basename'] ?? basename((string) ($att['filename'] ?? $attId));
$inline = in_array(Attachments::kind($mime), ['image', 'audio', 'video'], true);
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($file['body']));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . str_replace('"', '', $name) . '"');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=300');
echo $file['body'];
exit;
