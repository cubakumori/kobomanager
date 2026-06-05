<?php
/**
 * Sincronización de envíos de un formulario desde Kobo hacia submissions_cache.
 * Reutilizable por el cron (todos los formularios) y por el endpoint de
 * actualización de un único formulario.
 */
class SubmissionSync {
    /**
     * Trae a la caché los envíos nuevos/modificados de un formulario y actualiza
     * su estado. Devuelve el número de envíos upsertados. Marca sync_status='error'
     * y relanza KoboException si Kobo falla.
     */
    public static function syncForm(int $formId, string $assetUid, KoboClient $client): int {
        try {
            // Refrescar el esquema legible (labels) junto con los envíos. Es a prueba
            // de fallos: no interrumpe la sincronización si el contenido no se puede leer.
            FormSchema::fetchAndStore($formId, $assetUid, $client);

            // Cursor incremental: el envío más reciente que ya tenemos en caché.
            // Así el primer sync (caché vacía) trae todo el histórico, y los
            // siguientes solo lo nuevo. No depende de forms.last_synced_at (que
            // también lo fija el descubrimiento de formularios).
            $latest = DB::run(
                'SELECT MAX(submitted_at) AS m FROM submissions_cache WHERE form_id = ?',
                [$formId]
            )->fetch()['m'];
            $since = $latest ? date('c', strtotime($latest)) : null;
            $subs  = $client->getSubmissionsSince($assetUid, $since);

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
            return $count;
        } catch (KoboException $e) {
            DB::run(
                'UPDATE forms SET sync_status = \'error\', last_sync_error = ? WHERE id = ?',
                [$e->getMessage(), $formId]
            );
            throw $e;
        }
    }
}
