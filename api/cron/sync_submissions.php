<?php
/**
 * CRON: sincroniza los envíos desde Kobo hacia submissions_cache.
 *
 *   php api/cron/sync_submissions.php [account_id]
 *   crontab (cada 15 min):  0,15,30,45 * * * *  php /ruta/api/cron/sync_submissions.php
 *
 * Para cada formulario activo: pide a Kobo los envíos nuevos/modificados desde
 * last_synced_at y hace upsert en submissions_cache. Actualiza sync_status.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/Settings.php';
require __DIR__ . '/../lib/TokenVault.php';
require __DIR__ . '/../lib/KoboClient.php';
require __DIR__ . '/../lib/FormSchema.php';
require __DIR__ . '/../lib/SubmissionSync.php';

$onlyAccount = isset($argv[1]) ? (int) $argv[1] : null;

$accSql    = 'SELECT id, label, server_url, api_token FROM kobo_accounts WHERE active = 1';
$accParams = [];
if ($onlyAccount) {
    $accSql     .= ' AND id = ?';
    $accParams[] = $onlyAccount;
}
$accounts = DB::run($accSql, $accParams)->fetchAll();

$totalForms = 0;
$totalSubs  = 0;
$errors     = 0;

foreach ($accounts as $acc) {
    // Aislar cada cuenta: un token ilegible (clave rotada a medias, backup con otra
    // CONFIG_TOKEN_KEY) no debe tumbar el cron entero — las demás cuentas se
    // sincronizan igual y la corrida queda registrada para /health.
    try {
        $token = TokenVault::decrypt($acc['api_token']);
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, sprintf("[ERR] %s: token ilegible (%s)\n", $acc['label'], $e->getMessage()));
        continue;
    }
    $client = new KoboClient($acc['server_url'], $token);

    $forms = DB::run(
        'SELECT id, kobo_asset_uid FROM forms WHERE kobo_account_id = ? AND active = 1',
        [$acc['id']]
    )->fetchAll();

    foreach ($forms as $form) {
        $formId = (int) $form['id'];
        $totalForms++;
        try {
            $res = SubmissionSync::syncForm($formId, $form['kobo_asset_uid'], $client);
            $totalSubs += $res['upserted'];
            fwrite(STDOUT, sprintf(
                "[OK] %s / form %d: %d envíos%s\n",
                $acc['label'], $formId, $res['upserted'],
                $res['removed'] ? sprintf(', %d eliminados', $res['removed']) : ''
            ));
        } catch (KoboException $e) {
            // Otro proceso (cron solapado o sync manual) ya está sincronizando este
            // formulario: no es un error, simplemente se omite en esta corrida.
            if ($e->errorCode === 'SYNC_IN_PROGRESS') {
                fwrite(STDOUT, sprintf("[SKIP] %s / form %d: sincronización ya en curso\n", $acc['label'], $formId));
                continue;
            }
            $errors++;
            fwrite(STDERR, sprintf("[ERR] %s / form %d: %s (%s)\n", $acc['label'], $formId, $e->getMessage(), $e->errorCode));
        } catch (Throwable $e) {
            // Errores no-Kobo (PDO transitorio, bug): se aísla el formulario y se
            // continúa, para que un fallo puntual no impida el resto de la corrida
            // ni deje /health sin registro.
            $errors++;
            fwrite(STDERR, sprintf("[ERR] %s / form %d: %s\n", $acc['label'], $formId, $e->getMessage()));
        }
    }
}

// Registrar la ejecución para /health (observabilidad).
$onlyArg = $onlyAccount !== null ? (int) $onlyAccount : null;
Settings::recordCronRun('sync_submissions', [
    'ok'      => $errors === 0,
    'forms'   => $totalForms,
    'subs'    => $totalSubs,
    'errors'  => $errors,
    'account' => $onlyArg,
]);

fwrite(STDOUT, sprintf("Hecho: %d formularios, %d envíos sincronizados.\n", $totalForms, $totalSubs));

// Avisos casi inmediatos (frecuencias hourly/every_sync): tras cada pasada, un email
// agrupado por usuario con lo nuevo desde su marca de agua (lib/Notifier: scoping por
// filas, throttle y horario de silencio). Va DENTRO del cron a propósito: solo el
// cron marca la cadencia (las sync manuales o al iniciar sesión no disparan emails).
require __DIR__ . '/../lib/Mailer.php';
require __DIR__ . '/../lib/WebPush.php';
require __DIR__ . '/../lib/RowScope.php';
require __DIR__ . '/../lib/Notifier.php';
try {
    $notif = Notifier::run();
    Settings::recordCronRun('notifier', ['ok' => $notif['errors'] === 0] + $notif);
    fwrite(STDOUT, isset($notif['skipped'])
        ? sprintf("Avisos: omitidos (%s).\n", $notif['skipped'])
        : sprintf("Avisos: %d email(s) y %d push a %d usuario(s).\n", $notif['sent'], $notif['push_sent'], $notif['recipients']));
} catch (Throwable $e) {
    // Un fallo del notificador no debe marcar la sincronización como fallida.
    Settings::recordCronRun('notifier', ['ok' => false, 'error' => $e->getMessage()]);
    fwrite(STDERR, "[ERR] notifier: " . $e->getMessage() . "\n");
}
