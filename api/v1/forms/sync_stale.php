<?php
/**
 * POST /api/v1/forms/sync-stale   (cualquier usuario autenticado)
 * Sincroniza en una pasada los formularios VISIBLES para el usuario cuyos envíos
 * lleven más de 10 minutos sin sincronizar. Lo dispara el frontend tras el login,
 * en segundo plano, cuando el ajuste global `sync_on_login` está activo — es la red
 * de seguridad contra datos obsoletos pensada para instalaciones SIN cron.
 *
 * Con el ajuste apagado es un no-op (el endpoint no debe ser una vía para forzar
 * syncs que el admin no habilitó). El umbral de frescura evita tormentas de sync
 * con varios logins seguidos, y el candado por formulario de SubmissionSync hace
 * inofensivos los solapes con el cron o con otro login simultáneo (el segundo en
 * llegar se retira). Un error de Kobo en un formulario NO aborta la pasada.
 */

$user = Auth::require();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

if (!Settings::syncOnLogin()) {
    ErrorResponse::ok(['enabled' => false, 'synced' => 0, 'fresh' => 0, 'errors' => 0]);
}

// Pasada de FONDO fire-and-forget: no debe morir porque el navegador navegue o
// cierre la conexión a mitad (el front no espera la respuesta) ni por el tope de
// tiempo de PHP con muchos formularios. Cada sync individual persiste su progreso
// igualmente, pero así la pasada completa y su registro de auditoría no se cortan.
ignore_user_abort(true);
set_time_limit(0);

$staleSql = '(f.submissions_synced_at IS NULL OR f.submissions_synced_at < NOW() - INTERVAL 10 MINUTE)';
if ($user['role'] === 'admin') {
    $rows = DB::run(
        "SELECT f.id, f.kobo_asset_uid, a.server_url, a.api_token
         FROM forms f
         JOIN kobo_accounts a ON a.id = f.kobo_account_id AND a.active = 1
         WHERE f.active = 1 AND $staleSql",
        []
    )->fetchAll();
} else {
    $rows = DB::run(
        "SELECT f.id, f.kobo_asset_uid, a.server_url, a.api_token
         FROM forms f
         JOIN kobo_accounts a ON a.id = f.kobo_account_id AND a.active = 1
         JOIN user_form_permissions p ON p.form_id = f.id AND p.user_id = ? AND p.can_view = 1
         WHERE f.active = 1 AND $staleSql",
        [$user['id']]
    )->fetchAll();
}

$synced = 0;
$errors = 0;
foreach ($rows as $f) {
    try {
        $client = new KoboClient($f['server_url'], TokenVault::decrypt($f['api_token']));
        SubmissionSync::syncForm((int) $f['id'], $f['kobo_asset_uid'], $client);
        $synced++;
    } catch (Throwable $e) {
        // Incluye SYNC_IN_PROGRESS (candado: otro proceso ya lo está haciendo) y
        // errores de Kobo: se sigue con el resto; el cron o el próximo login recogen.
        $errors++;
    }
}

Audit::log($user['id'], 'sync_stale', null, null, ['synced' => $synced, 'errors' => $errors]);
ErrorResponse::ok(['enabled' => true, 'synced' => $synced, 'errors' => $errors]);
