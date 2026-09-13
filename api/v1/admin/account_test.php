<?php
/**
 * POST /api/v1/admin/accounts/test   (solo admin; bloqueado en demo)
 * Body: { server_url, api_token?, account_id? }
 *
 * «Probar conexión» antes de guardar (o al editar) una cuenta Kobo: llama al
 * listado de assets con las credenciales indicadas y devuelve cuántos formularios
 * (assets tipo survey) ve el token. Sin esto, un token mal pegado o una URL con
 * errata solo se descubría al sincronizar. No escribe nada.
 *
 * Con `api_token` vacío y `account_id` presente se usa el token YA guardado de esa
 * cuenta (edición sin cambiar el token); el token nunca vuelve al navegador.
 */

$admin = Auth::requireAdmin();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$in        = Request::required(['server_url']);
$body      = Request::json();
$serverUrl = rtrim($in['server_url'], '/');
if (!filter_var($serverUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $serverUrl)) {
    ErrorResponse::send('VALIDATION_ERROR', 'server_url no es una URL http(s) válida');
}

$token = isset($body['api_token']) && is_string($body['api_token']) ? trim($body['api_token']) : '';
if ($token === '') {
    $accId = (int) ($body['account_id'] ?? 0);
    $acc   = $accId > 0 ? DB::run('SELECT api_token FROM kobo_accounts WHERE id = ?', [$accId])->fetch() : null;
    if (!$acc) {
        ErrorResponse::send('VALIDATION_ERROR', 'Falta el API token');
    }
    $token = TokenVault::decrypt($acc['api_token']);
}

try {
    $client = new KoboClient($serverUrl, $token);
    $assets = $client->getAssets();
} catch (KoboException $e) {
    ErrorResponse::send($e->errorCode, $e->getMessage());
}

Audit::log($admin['id'], 'test_kobo_account', null, null, ['server_url' => $serverUrl, 'forms' => count($assets)]);

ErrorResponse::ok([
    'ok'    => true,
    'forms' => count($assets),
    // Nombres de muestra (hasta 5) para que el admin reconozca la cuenta a la primera.
    'sample' => array_values(array_slice(array_map(fn($a) => (string) ($a['name'] ?? ''), $assets), 0, 5)),
]);
