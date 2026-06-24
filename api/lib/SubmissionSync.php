<?php
/**
 * Sincronización de envíos de un formulario desde Kobo hacia submissions_cache.
 * Reutilizable por el cron (todos los formularios) y por el endpoint de
 * actualización de un único formulario.
 */
class SubmissionSync {
    /**
     * Trae a la caché los envíos de un formulario, actualiza su estado y reconcilia
     * las bajas (envíos borrados en Kobo). Devuelve ['upserted' => n, 'removed' => n].
     * Marca sync_status='error' y relanza KoboException si Kobo falla.
     *
     * Modos:
     *  - incremental (por defecto): trae solo los envíos NUEVOS (cursor = envío más
     *    reciente en caché) y borra de la caché los que ya no existen en Kobo
     *    (barrido barato pidiendo solo los `_id`). No detecta ediciones hechas
     *    directamente en Kobo (no hay fecha de modificación en su API).
     *  - completo ($full=true): re-descarga TODOS los envíos y reconcilia por `_uuid`,
     *    de modo que también refleja ediciones externas (una edición en la UI de Kobo
     *    conserva el `_id` pero cambia el `_uuid`) y elimina las bajas.
     */
    public static function syncForm(int $formId, string $assetUid, KoboClient $client, bool $full = false): array {
        try {
            // Refrescar el esquema legible (labels) junto con los envíos. Es a prueba
            // de fallos: no interrumpe la sincronización si el contenido no se puede leer.
            FormSchema::fetchAndStore($formId, $assetUid, $client);

            // Cursor incremental: el envío más reciente que ya tenemos en caché.
            // Así el primer sync (caché vacía) trae todo el histórico, y los
            // siguientes solo lo nuevo. No depende de forms.last_synced_at (que
            // también lo fija el descubrimiento de formularios). En modo completo
            // se ignora para re-traer todo.
            $since = null;
            if (!$full) {
                $latest = DB::run(
                    'SELECT MAX(submitted_at) AS m FROM submissions_cache WHERE form_id = ?',
                    [$formId]
                )->fetch()['m'];
                $since = $latest ? date('c', strtotime($latest)) : null;
            }
            $subs = $client->getSubmissionsSince($assetUid, $since);

            // Mapa de etiquetas de opción (todas las traducciones) para enriquecer el
            // texto buscable: se calcula UNA vez por formulario desde el esquema recién
            // refrescado y se reutiliza en cada fila.
            $schemaRow   = DB::run('SELECT schema_json FROM forms WHERE id = ?', [$formId])->fetch();
            $optionLabels = FormSchema::searchOptionLabels(
                ($schemaRow && $schemaRow['schema_json']) ? json_decode($schemaRow['schema_json'], true) : null
            );

            $count    = 0;
            $seenUids = [];
            foreach ($subs as $sub) {
                $uid = $sub['_uuid'] ?? (isset($sub['_id']) ? (string) $sub['_id'] : null);
                if (!$uid) continue;
                $seenUids[$uid] = true;

                // `_submission_time` viene de Kobo en UTC; lo proyectamos a la columna
                // DATETIME anclado en UTC (no en la zona del servidor PHP), para que las
                // agregaciones por día/mes/tendencia sean consistentes con la hora/día
                // derivados y robustas en servidores con TZ ≠ UTC (ver Derived::ts).
                $submittedAt = null;
                if (is_string($submittedRaw = $sub['_submission_time'] ?? null) && trim($submittedRaw) !== '') {
                    try {
                        $submittedAt = (new DateTime($submittedRaw, new DateTimeZone('UTC')))
                            ->setTimezone(new DateTimeZone('UTC'))
                            ->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $submittedAt = null;
                    }
                }

                DB::run(
                    'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, search_text, submitted_at, last_synced_at)
                     VALUES (?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        json_payload   = VALUES(json_payload),
                        search_text    = VALUES(search_text),
                        submitted_at   = VALUES(submitted_at),
                        last_synced_at = NOW()',
                    [$formId, $uid, json_encode($sub, JSON_UNESCAPED_UNICODE), SubmissionSearch::textFor($sub, $optionLabels), $submittedAt]
                );
                $count++;
            }

            // Pull del estado de validación nativo de Kobo (siempre, en ambos modos):
            // el cursor incremental no re-trae envíos viejos cuyo `_validation_status`
            // cambió en Kobo, así que se reconcilia con un barrido ligero aparte.
            self::reconcileValidation($formId, $client, $assetUid);

            $removed = $full
                ? self::reconcileFull($formId, array_keys($seenUids))
                : self::reconcileDeletions($formId, $client, $assetUid);

            DB::run(
                'UPDATE forms SET last_synced_at = NOW(), submissions_synced_at = NOW(),
                                  sync_status = \'success\', last_sync_error = NULL WHERE id = ?',
                [$formId]
            );
            return ['upserted' => $count, 'removed' => $removed];
        } catch (KoboException $e) {
            DB::run(
                'UPDATE forms SET sync_status = \'error\', last_sync_error = ? WHERE id = ?',
                [$e->getMessage(), $formId]
            );
            throw $e;
        }
    }

    /**
     * Pull del estado de validación: reconcilia el `_validation_status` nativo de Kobo
     * con el log de revisiones interno mediante un merge a 3 vías por envío:
     *   - koboNow  = estado actual en Kobo (barrido ligero getValidationStatuses);
     *   - baseline = último uid de Kobo visto (submissions_cache.kobo_validation_seen);
     *   - localNow = última revisión interna (MAX(id); 'pending' si no hay).
     * Si koboNow ≠ baseline, Kobo cambió fuera de la app → se actualiza la línea base y,
     * si además koboNow ≠ localNow, se inserta una revisión sintética source='kobo'
     * (user_id NULL) que pasa a ser la última por MAX(id) ⇒ GANA KOBO en conflicto.
     * Devuelve cuántas revisiones sintéticas se crearon.
     */
    private static function reconcileValidation(int $formId, KoboClient $client, string $assetUid): int {
        $koboMap = $client->getValidationStatuses($assetUid);

        // Estado local: línea base por envío + última revisión (la de mayor id).
        $rows = DB::run(
            'SELECT sc.submission_uid, sc.kobo_validation_seen, lr.status AS local_status
             FROM submissions_cache sc
             LEFT JOIN (
                SELECT r.submission_uid, r.status
                FROM submission_reviews r
                JOIN (SELECT submission_uid, MAX(id) AS mid FROM submission_reviews GROUP BY submission_uid) m
                  ON m.mid = r.id
             ) lr ON lr.submission_uid = sc.submission_uid
             WHERE sc.form_id = ?',
            [$formId]
        )->fetchAll();

        $pdo      = DB::conn();
        $created  = 0;
        $upd      = $pdo->prepare('UPDATE submissions_cache SET kobo_validation_seen = ? WHERE submission_uid = ?');
        $ins      = $pdo->prepare(
            "INSERT INTO submission_reviews (submission_uid, user_id, source, status, comment)
             VALUES (?, NULL, 'kobo', ?, NULL)"
        );

        foreach ($rows as $r) {
            $sUid = $r['submission_uid'];
            // Si el envío no está en el mapa de Kobo (p. ej. recién borrado), se deja al
            // barrido de bajas; no se toca su validación aquí.
            if (!array_key_exists($sUid, $koboMap)) {
                continue;
            }
            $koboUid  = $koboMap[$sUid];                            // '' = sin estado
            $koboNow  = ValidationStatus::fromKobo($koboUid);
            $baseline = ValidationStatus::fromKobo($r['kobo_validation_seen']);
            if ($koboNow === $baseline) {
                continue; // Kobo no cambió desde la última vez → nada que hacer.
            }

            $upd->execute([$koboUid, $sUid]);
            $localNow = $r['local_status'] ?? 'pending';
            if ($koboNow !== $localNow) {
                $ins->execute([$sUid, $koboNow]);
                $created++;
            }
        }
        return $created;
    }

    /**
     * Barrido de bajas en modo completo: borra de la caché los envíos cuyo `_uuid`
     * no está entre los recién traídos (cubre borrados y ediciones externas que
     * cambian el uuid). Devuelve cuántos se eliminaron.
     */
    private static function reconcileFull(int $formId, array $keepUids): int {
        $rows = DB::run('SELECT id, submission_uid FROM submissions_cache WHERE form_id = ?', [$formId])->fetchAll();
        if (!$rows) return 0;
        $keep   = array_flip($keepUids);
        $toDrop = [];
        foreach ($rows as $r) {
            if (!isset($keep[$r['submission_uid']])) $toDrop[] = (int) $r['id'];
        }
        return self::deleteByIds($toDrop);
    }

    /**
     * Barrido de bajas incremental: pide a Kobo solo los `_id` actuales (barato) y
     * borra de la caché los envíos cuyo `_id` ya no existe. Devuelve cuántos se eliminaron.
     */
    private static function reconcileDeletions(int $formId, KoboClient $client, string $assetUid): int {
        $liveIds = array_flip($client->getAllSubmissionIds($assetUid));

        $rows = DB::run(
            "SELECT id, CAST(JSON_EXTRACT(json_payload, '$._id') AS UNSIGNED) AS kobo_id
             FROM submissions_cache WHERE form_id = ?",
            [$formId]
        )->fetchAll();

        $toDrop = [];
        foreach ($rows as $r) {
            $kid = (int) $r['kobo_id'];
            // Si no podemos determinar el _id (0), conservar por prudencia.
            if ($kid !== 0 && !isset($liveIds[$kid])) $toDrop[] = (int) $r['id'];
        }
        return self::deleteByIds($toDrop);
    }

    /** Borra filas de submissions_cache por su PK, en lotes. */
    private static function deleteByIds(array $ids): int {
        if (!$ids) return 0;
        $removed = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $removed += DB::run("DELETE FROM submissions_cache WHERE id IN ($ph)", $chunk)->rowCount();
        }
        return $removed;
    }
}
