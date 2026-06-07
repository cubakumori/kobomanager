<?php
/**
 * Valores «calculados» (derivados) de un envío de Kobo.
 *
 * A partir del payload de un envío y del esquema normalizado del formulario
 * (`forms.schema_json` decodificado, NO el resuelto a un idioma), computa
 * métricas que Kobo no entrega directamente: duración, completitud, adjuntos
 * por tipo, geolocalización, retraso de subida, hora/día, etc.
 *
 * Es una clase pura (sin BD ni red): se reutiliza idéntica en el detalle, la
 * lista (tabla) y la exportación CSV. Como solo opera sobre payloads que el
 * usuario ya tiene permiso de ver, respeta permisos y RowScope «gratis».
 *
 * Todas las claves del array devuelto están SIEMPRE presentes; los valores que
 * no se pueden calcular (p. ej. duración sin `start`/`end`) son `null` y la UI
 * los muestra como «—».
 */
class Derived {

    /** Ids de las métricas ofrecidas como columnas opcionales de la tabla/CSV. */
    public const TABLE_COLUMNS = ['duration', 'has_attachments', 'has_geo'];

    /**
     * @param array       $payload     Datos del envío (json_payload decodificado).
     * @param array|null  $schema      Esquema normalizado del formulario (con `fields` y `meta`).
     * @param string|null $submittedAt Fecha de envío de la caché (respaldo de `_submission_time`).
     */
    public static function compute(array $payload, ?array $schema, ?string $submittedAt = null): array {
        $fields = $schema['fields'] ?? [];
        $metaF  = $schema['meta'] ?? [];

        // --- Marcas de tiempo: start / end / _submission_time ---
        $startTs = self::ts($payload[$metaF['start'] ?? 'start'] ?? ($payload['start'] ?? null));
        $endTs   = self::ts($payload[$metaF['end'] ?? 'end'] ?? ($payload['end'] ?? null));
        $subTs   = self::ts($payload['_submission_time'] ?? $submittedAt);

        $duration = ($startTs !== null && $endTs !== null && $endTs >= $startTs) ? $endTs - $startTs : null;
        $uploadDelay = ($endTs !== null && $subTs !== null && $subTs >= $endTs) ? $subTs - $endTs : null;

        // --- Completitud: campos del esquema con valor no vacío / total ---
        $questions = count($fields);
        $answered  = 0;
        foreach (array_keys($fields) as $path) {
            if (self::nonEmpty($payload[$path] ?? null)) $answered++;
        }
        $completeness = $questions > 0 ? round($answered / $questions, 4) : null;
        $speed        = ($duration !== null && $questions > 0) ? round($duration / $questions, 2) : null;

        // --- Adjuntos por tipo ---
        $byKind = ['image' => 0, 'audio' => 0, 'video' => 0, 'file' => 0];
        foreach (($payload['_attachments'] ?? []) as $a) {
            $byKind[self::attKind((string) ($a['mimetype'] ?? ''))]++;
        }
        $attTotal = array_sum($byKind);

        // --- Geolocalización (reusa el parser de Geo, cubre point/line/polygon/_geolocation) ---
        $hasGeo = $schema !== null && Geo::features($payload, $schema) !== [];

        // --- Hora / día del envío (de _submission_time) ---
        $hour = $subTs !== null ? (int) date('G', $subTs) : null;   // 0–23
        $dow  = $subTs !== null ? (int) date('w', $subTs) : null;   // 0=domingo … 6=sábado

        // --- Estado de validación de Kobo (objeto con uid/label, o vacío) ---
        $vs = $payload['_validation_status'] ?? null;
        $validation = (is_array($vs) && isset($vs['uid']) && $vs['uid'] !== '') ? (string) $vs['uid'] : null;

        return [
            'duration_s'          => $duration,
            'upload_delay_s'      => $uploadDelay,
            'questions'           => $questions,
            'answered'            => $answered,
            'completeness'        => $completeness,
            'speed_s_per_q'       => $speed,
            'attachments_total'   => $attTotal,
            'attachments_by_kind' => $byKind,
            'has_attachments'     => $attTotal > 0,
            'has_geo'             => $hasGeo,
            'submitted_hour'      => $hour,
            'submitted_dow'       => $dow,
            'submitted_by'        => self::strOrNull($payload['_submitted_by'] ?? null),
            'version'             => self::strOrNull($payload['__version__'] ?? null),
            'validation_status'   => $validation,
            'tags_count'          => is_array($payload['_tags'] ?? null) ? count($payload['_tags']) : 0,
            'notes_count'         => is_array($payload['_notes'] ?? null) ? count($payload['_notes']) : 0,
        ];
    }

    // ---------- internos ----------

    /** Marca de tiempo ISO de Kobo → epoch (segundos), o null. */
    private static function ts($v): ?int {
        if (!is_string($v) || trim($v) === '') return null;
        $t = strtotime($v);
        return $t === false ? null : $t;
    }

    /** ¿El valor cuenta como respondido? (no null, no cadena vacía, no array vacío). */
    private static function nonEmpty($v): bool {
        if ($v === null) return false;
        if (is_string($v)) return trim($v) !== '';
        if (is_array($v)) return $v !== [];
        return true; // números, booleanos
    }

    /** Clasifica un mimetype en image|audio|video|file (igual que el detalle). */
    private static function attKind(string $mime): string {
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if (str_starts_with($mime, 'video/')) return 'video';
        return 'file';
    }

    /** Devuelve la cadena no vacía, o null. */
    private static function strOrNull($v): ?string {
        if ($v === null) return null;
        $s = (string) $v;
        return $s === '' ? null : $s;
    }
}
