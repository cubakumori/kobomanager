<?php
/**
 * Núcleo compartido de la EDICIÓN REAL de un envío (escribir en Kobo y, solo si
 * Kobo acepta, actualizar la caché local): extraído del PUT de submissions/item.php
 * para que la resolución de incongruencias equipo ↔ meta-equipo (edición en lote
 * desde el Control de calidad) aplique EXACTAMENTE el mismo flujo — PATCH a Kobo
 * (que crea un `_uuid` NUEVO en cada edición), migración de la clave de caché,
 * arrastre del historial de revisiones y auditoría por edición.
 *
 * Las validaciones de PERMISO (can_edit, RowScope, FieldScope, veto de metadatos)
 * son de los endpoints: aquí vive solo la mecánica común, que da por buenas las
 * entradas ya validadas.
 */
class SubmissionEdit {

    /**
     * Aplica una edición a UN envío.
     *
     * @param array      $sub    Fila con id, submission_uid, json_payload,
     *                           kobo_asset_uid y schema_json (crudo, sin decodificar).
     * @param array      $data   Campos a escribir (ruta => valor), ya validados.
     * @param KoboClient $client Cliente de la cuenta del formulario (reutilizable
     *                           entre las ediciones de una tanda).
     * @param int        $userId Autor (para la auditoría).
     * @param int        $formId Formulario (para la auditoría).
     *
     * @return array{data: array, submission_uid: string, changed_uuid: bool}
     *
     * @throws KoboException    si Kobo rechaza la edición (no se toca nada local).
     * @throws RuntimeException si Kobo YA aceptó pero la caché local no se pudo
     *                          actualizar (el cambio en Kobo es real: resincronizar).
     */
    public static function apply(array $sub, array $data, KoboClient $client, int $userId, int $formId): array {
        $uid     = (string) $sub['submission_uid'];
        $payload = json_decode($sub['json_payload'], true) ?: [];
        $koboId  = $payload['_id'] ?? null;
        if (!$koboId) {
            throw new RuntimeException('El envío en caché no tiene _id de Kobo');
        }
        $schemaRaw = $sub['schema_json'] ? json_decode($sub['schema_json'], true) : null;

        // Valores anteriores (para auditoría).
        $before = [];
        foreach ($data as $k => $v) {
            $before[$k] = $payload[$k] ?? null;
        }

        // Carrera edición↔sync: un sync `full` concurrente re-descargaría el _uuid
        // nuevo como fila aparte y `reconcileFull` borraría esta, dejando las
        // revisiones huérfanas. Mismo lock por formulario que el sync y que los
        // endpoints de revisión; aquí, a diferencia de la revisión, si no se
        // obtiene se ABORTA antes de tocar Kobo (todavía no se escribió nada).
        $lockName = 'km.sync.form.' . $formId;
        $gotLock  = ((int) DB::run('SELECT GET_LOCK(?, 5) AS l', [$lockName])->fetch()['l']) === 1;
        if (!$gotLock) {
            throw new KoboException('SYNC_IN_PROGRESS', 'Ya hay una sincronización de este formulario en curso');
        }
        try {

        // 1) Escribir en Kobo (lanza KoboException si falla).
        //    Una edición en Kobo crea una versión nueva con un _uuid NUEVO (el _id
        //    numérico se conserva); editSubmission devuelve ese _uuid resultante.
        $newUuid = $client->editSubmission($sub['kobo_asset_uid'], (int) $koboId, $data);

        // 2) Solo si Kobo aceptó, actualizar la caché.
        foreach ($data as $k => $v) {
            $payload[$k] = $v;
        }

        // Si el _uuid cambió, migramos la clave de caché y arrastramos el historial de
        // revisiones (indexado por submission_uid = _uuid) para no perderlo en el
        // próximo resync `full` (que reconcilia por _uuid y borraría la fila antigua).
        $changedUuid = ($newUuid !== '' && $newUuid !== $uid);
        if ($changedUuid) {
            $payload['_uuid'] = $newUuid;
        }

        $conn = DB::conn();
        $conn->beginTransaction();
        try {
            // La edición puede cambiar campos que alimentan las columnas materializadas
            // (p. ej. un geopoint) → se recalculan junto con el payload.
            $cc = Derived::cacheColumns($payload, $schemaRaw);
            DB::run(
                'UPDATE submissions_cache SET submission_uid = ?, json_payload = ?, search_text = ?,
                        kobo_id = ?, duration_s = ?, att_count = ?, has_geo = ?
                 WHERE id = ?',
                [
                    $changedUuid ? $newUuid : $uid,
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                    SubmissionSearch::textFor($payload, FormSchema::searchOptionLabels($schemaRaw)),
                    $cc['kobo_id'], $cc['duration_s'], $cc['att_count'], $cc['has_geo'],
                    $sub['id'],
                ]
            );
            if ($changedUuid) {
                // Acotado al formulario: el mismo uid puede existir en otro formulario
                // (form_id IS NULL = filas antiguas anteriores al backfill de 1.52.0).
                DB::run(
                    'UPDATE submission_reviews SET submission_uid = ?, form_id = ?
                     WHERE submission_uid = ? AND (form_id = ? OR form_id IS NULL)',
                    [$newUuid, $formId, $uid, $formId]
                );
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            // Kobo ya aceptó el cambio; se informa para que el usuario resincronice
            // (la edición en Kobo es real).
            throw new RuntimeException('La edición se guardó en Kobo pero falló la actualización de la caché local');
        }

        Audit::log($userId, 'edit', $formId, $uid, ['before' => $before, 'after' => $data, 'new_uid' => $changedUuid ? $newUuid : null]);

        return [
            'data'           => $payload,
            'submission_uid' => $changedUuid ? $newUuid : $uid,
            'changed_uuid'   => $changedUuid,
        ];

        } finally {
            DB::run('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
}
