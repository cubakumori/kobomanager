<?php
/**
 * CRON: sincroniza los envíos desde Kobo hacia submissions_cache.
 *
 *   php api/cron/sync_submissions.php [account_id]
 *   crontab:  */15 * * * *  php /ruta/api/cron/sync_submissions.php
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
require __DIR__ . '/../lib/TokenVault.php';
require __DIR__ . '/../lib/KoboClient.php';

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

foreach ($accounts as $acc) {
    $token = TokenVault::decrypt($acc['api_token']);

    $forms = DB::run(
        'SELECT id, kobo_asset_uid, last_synced_at FROM forms WHERE kobo_account_id = ? AND active = 1',
        [$acc['id']]
    )->fetchAll();

    foreach ($forms as $form) {
        $formId = (int) $form['id'];
        $totalForms++;
        try {
            $client = new KoboClient($acc['server_url'], $token);
            // Filtro incremental: solo lo enviado tras la última sync (ISO 8601).
            $since = $form['last_synced_at']
                ? date('c', strtotime($form['last_synced_at']))
                : null;

            $subs  = $client->getSubmissionsSince($form['kobo_asset_uid'], $since);
            $count = 0;

            foreach ($subs as $sub) {
                $uid = $sub['_uuid'] ?? (isset($sub['_id']) ? (string) $sub['_id'] : null);
                if (!$uid) continue;

                $submittedRaw = $sub['_submission_time'] ?? null;
                $submittedAt  = $submittedRaw ? date('Y-m-d H:i:s', strtotime($submittedRaw)) : null;

                DB::run(
                    'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at, last_synced_at)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        json_payload   = VALUES(json_payload),
                        submitted_at   = VALUES(submitted_at),
                        last_synced_at = NOW()',
                    [$formId, $uid, json_encode($sub, JSON_UNESCAPED_UNICODE), $submittedAt]
                );
                $count++;
            }

            DB::run(
                'UPDATE forms SET last_synced_at = NOW(), sync_status = \'success\', last_sync_error = NULL WHERE id = ?',
                [$formId]
            );
            $totalSubs += $count;
            fwrite(STDOUT, sprintf("[OK] %s / form %d: %d envíos\n", $acc['label'], $formId, $count));
        } catch (KoboException $e) {
            DB::run(
                'UPDATE forms SET sync_status = \'error\', last_sync_error = ? WHERE id = ?',
                [$e->getMessage(), $formId]
            );
            fwrite(STDERR, sprintf("[ERR] %s / form %d: %s (%s)\n", $acc['label'], $formId, $e->getMessage(), $e->errorCode));
        }
    }
}

fwrite(STDOUT, sprintf("Hecho: %d formularios, %d envíos sincronizados.\n", $totalForms, $totalSubs));
