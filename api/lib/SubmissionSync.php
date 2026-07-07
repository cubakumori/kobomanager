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
        // Lock por formulario (GET_LOCK de MySQL, sin espera): dos sincronizaciones
        // simultáneas del mismo formulario (un cron retrasado + el siguiente, o el
        // cron + un «Actualizar» manual) duplicarían revisiones sintéticas en
        // reconcileValidation. El segundo en llegar se retira sin tocar nada; el
        // lock se libera en el finally y, si el proceso muere, con su conexión.
        $lockName = 'km.sync.form.' . $formId;
        $got = (int) DB::run('SELECT GET_LOCK(?, 0) AS l', [$lockName])->fetch()['l'];
        if ($got !== 1) {
            throw new KoboException('SYNC_IN_PROGRESS', 'Ya hay una sincronización de este formulario en curso');
        }
        try {
            return self::syncFormLocked($formId, $assetUid, $client, $full);
        } finally {
            DB::run('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /** Cuerpo de la sincronización; se ejecuta con el lock del formulario ya adquirido. */
    private static function syncFormLocked(int $formId, string $assetUid, KoboClient $client, bool $full): array {
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
            // Mapa de etiquetas de opción (todas las traducciones) para enriquecer el
            // texto buscable: se calcula UNA vez por formulario desde el esquema recién
            // refrescado y se reutiliza en cada fila. El esquema decodificado alimenta
            // también las columnas materializadas (duración, geo).
            $schemaRow    = DB::run('SELECT schema_json FROM forms WHERE id = ?', [$formId])->fetch();
            $schemaRaw    = ($schemaRow && $schemaRow['schema_json']) ? json_decode($schemaRow['schema_json'], true) : null;
            $optionLabels = FormSchema::searchOptionLabels($schemaRaw);

            // Upsert por PÁGINAS: el generador entrega cada página según llega (el
            // histórico completo nunca vive entero en memoria) y cada página se
            // escribe con UN prepared statement reutilizado dentro de una transacción
            // (un commit por página, no uno por fila: en InnoDB es 10-50× más rápido
            // para el primer sync de un formulario grande).
            $pdo  = DB::conn();
            $stmt = $pdo->prepare(
                'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, search_text, submitted_at,
                                                kobo_id, duration_s, att_count, has_geo, last_synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    json_payload   = VALUES(json_payload),
                    search_text    = VALUES(search_text),
                    submitted_at   = VALUES(submitted_at),
                    kobo_id        = VALUES(kobo_id),
                    duration_s     = VALUES(duration_s),
                    att_count      = VALUES(att_count),
                    has_geo        = VALUES(has_geo),
                    last_synced_at = NOW()'
            );

            $count    = 0;
            $seenUids = [];
            foreach ($client->getSubmissionsSince($assetUid, $since) as $pageRows) {
                $pdo->beginTransaction();
                try {
                    foreach ($pageRows as $sub) {
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

                        $cc = Derived::cacheColumns($sub, $schemaRaw);
                        $stmt->execute([
                            $formId, $uid,
                            json_encode($sub, JSON_UNESCAPED_UNICODE),
                            SubmissionSearch::textFor($sub, $optionLabels),
                            $submittedAt,
                            $cc['kobo_id'], $cc['duration_s'], $cc['att_count'], $cc['has_geo'],
                        ]);
                        $count++;
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
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

        // Estado local: línea base por envío + estado vigente (columna desnormalizada
        // review_status, mantenida por ValidationStatus::recordReview — ya no hace
        // falta materializar el log de revisiones entero).
        $rows = DB::run(
            'SELECT submission_uid, kobo_validation_seen, review_status AS local_status
             FROM submissions_cache
             WHERE form_id = ?',
            [$formId]
        )->fetchAll();

        $pdo     = DB::conn();
        $created = 0;
        $upd     = $pdo->prepare('UPDATE submissions_cache SET kobo_validation_seen = ? WHERE submission_uid = ?');

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
                ValidationStatus::recordReview($sUid, null, 'kobo', $koboNow);
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
        // Guardia anti-vaciado: una lista viva VACÍA con caché poblada es mucho más
        // probablemente un fallo aguas arriba (respuesta anómala no detectada, bug)
        // que un borrado real del 100 % de los envíos en Kobo. No se borra nada; si
        // el formulario de verdad quedó vacío, la caché se limpia al desactivar/
        // eliminar el formulario o cuando Kobo devuelva al menos un envío vivo.
        if (!$keepUids) return 0;
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

        // kobo_id es la columna materializada por el sync; NULL (fila anterior al
        // backfill, o envío sin _id) se conserva por prudencia, como siempre.
        $rows = DB::run(
            'SELECT id, kobo_id FROM submissions_cache WHERE form_id = ?',
            [$formId]
        )->fetchAll();

        // Guardia anti-vaciado (espejo de reconcileFull): sin ningún _id vivo pero
        // con caché poblada, no borrar nada en un cron rutinario.
        if (!$liveIds && $rows) return 0;

        $toDrop = [];
        foreach ($rows as $r) {
            $kid = (int) $r['kobo_id'];
            // Si no podemos determinar el _id (0), conservar por prudencia.
            if ($kid !== 0 && !isset($liveIds[$kid])) $toDrop[] = (int) $r['id'];
        }
        return self::deleteByIds($toDrop);
    }

    /**
     * BACKFILL de las columnas materializadas (kobo_id, duration_s, att_count,
     * has_geo) para filas creadas antes de que existieran. Lo usa cli/migrate.php
     * al actualizar una instalación; es idempotente y procesa por lotes keyset
     * (memoria acotada). Devuelve cuántas filas recalculó.
     */
    public static function recomputeCacheColumns(): int {
        // Esquema por formulario (una vez), para duración (claves meta) y geo.
        $schemas = [];
        foreach (DB::run('SELECT id, schema_json FROM forms')->fetchAll() as $f) {
            $schemas[(int) $f['id']] = $f['schema_json'] ? json_decode($f['schema_json'], true) : null;
        }

        $pdo = DB::conn();
        $upd = $pdo->prepare(
            'UPDATE submissions_cache SET kobo_id = ?, duration_s = ?, att_count = ?, has_geo = ? WHERE id = ?'
        );

        $done   = 0;
        $lastId = 0;
        do {
            $rows = DB::run(
                'SELECT id, form_id, json_payload FROM submissions_cache WHERE id > ? ORDER BY id LIMIT 500',
                [$lastId]
            )->fetchAll();
            if (!$rows) break;
            $pdo->beginTransaction();
            try {
                foreach ($rows as $r) {
                    $cc = Derived::cacheColumns(
                        json_decode($r['json_payload'], true) ?: [],
                        $schemas[(int) $r['form_id']] ?? null
                    );
                    $upd->execute([$cc['kobo_id'], $cc['duration_s'], $cc['att_count'], $cc['has_geo'], (int) $r['id']]);
                    $done++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $lastId = (int) end($rows)['id'];
        } while (count($rows) === 500);
        return $done;
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
