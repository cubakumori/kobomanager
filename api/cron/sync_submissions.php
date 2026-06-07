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
    $token  = TokenVault::decrypt($acc['api_token']);
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
            $errors++;
            fwrite(STDERR, sprintf("[ERR] %s / form %d: %s (%s)\n", $acc['label'], $formId, $e->getMessage(), $e->errorCode));
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
