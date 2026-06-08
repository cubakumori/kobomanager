<?php
/**
 * Permisos a nivel de columna: oculta CIERTOS campos de un formulario a un viewer
 * (o a un enlace compartido), recortándolos de toda salida de lectura.
 *
 * Gemelo de RowScope: mientras RowScope decide QUÉ filas (envíos) se ven —filtrando
 * sobre el payload completo—, FieldScope decide QUÉ campos salen, recortando claves
 * del payload DESPUÉS de aplicar el scoping. Son ortogonales y componen sin conflicto
 * (p. ej. «filtra por región pero no muestres la columna región» funciona solo).
 *
 * Forma del filtro (denylist): { "hidden": ["<clave de envío>", "g_a/region", ...] }
 *   - NULL / sin claves  → sin restricción (ve todos los campos). Retrocompatible.
 *   - cada clave es la ruta tal como aparece en el JSON del envío y en `scope-fields`
 *     (hoja `region` o ruta de grupo `g_a/region`).
 *
 * Se guarda en `user_form_permissions.field_filter` (por usuario+formulario) y en
 * `share_links.field_filter` (por enlace). Los administradores no tienen restricción.
 */
class FieldScope {

    /**
     * Regla normalizada del usuario para un formulario, o null si no oculta nada.
     * Admin → null (bypass). Viewer sin fila o con field_filter vacío → null.
     */
    public static function ruleForUser(array $user, int $formId): ?array {
        if (($user['role'] ?? '') === 'admin') {
            return null;
        }
        $row = DB::run(
            'SELECT field_filter FROM user_form_permissions WHERE user_id = ? AND form_id = ?',
            [$user['id'], $formId]
        )->fetch();
        if (!$row || $row['field_filter'] === null || $row['field_filter'] === '') {
            return null;
        }
        return self::normalize(json_decode((string) $row['field_filter'], true));
    }

    /** Regla de ocultado de columnas de un enlace compartido (canónica) o null. */
    public static function ruleForLink(array $link): ?array {
        return self::normalize(
            ($link['field_filter'] ?? null) ? json_decode((string) $link['field_filter'], true) : null
        );
    }

    /**
     * Normaliza un filtro crudo (de BD o enviado por el admin) a `{hidden:[...]}`,
     * o null si no hay campos que ocultar. Acepta `{hidden:[...]}` o una lista pelada.
     */
    public static function normalize($raw): ?array {
        if (is_array($raw) && array_is_list($raw)) {
            $raw = ['hidden' => $raw];
        }
        if (!is_array($raw)) {
            return null;
        }
        $list = $raw['hidden'] ?? null;
        if (!is_array($list)) {
            return null;
        }
        $out = [];
        foreach ($list as $k) {
            $k = trim((string) $k);
            // Nunca se ocultan metadatos de Kobo (`_*`): no son columnas de datos y
            // varios (adjuntos, geo) se gestionan aparte en apply().
            if ($k === '' || str_starts_with($k, '_')) continue;
            $out[$k] = true;
        }
        $out = array_keys($out);
        return $out ? ['hidden' => $out] : null;
    }

    /** Lista de claves ocultas (o [] si no hay restricción). */
    public static function hidden(?array $rule): array {
        return $rule['hidden'] ?? [];
    }

    /** ¿La clave `$key` está oculta para esta regla? */
    public static function isHidden(?array $rule, string $key): bool {
        return $rule !== null && in_array($key, $rule['hidden'], true);
    }

    /**
     * Rutas de campos de datos VISIBLES del esquema (las del esquema, no `_*`, menos
     * las ocultas). Las usa la búsqueda restringida (SubmissionSearch::clauseVisible).
     */
    public static function visiblePaths(?array $rule, ?array $schema): array {
        $hidden = array_flip(self::hidden($rule));
        $paths  = [];
        foreach (array_keys($schema['fields'] ?? []) as $k) {
            $k = (string) $k;
            if (str_starts_with($k, '_') || isset($hidden[$k])) continue;
            $paths[] = $k;
        }
        return $paths;
    }

    /**
     * Recorta el esquema RESUELTO (de FormSchema::resolve: {labels, options, multi})
     * quitando las claves ocultas. Evita filtrar incluso la ETIQUETA de un campo
     * oculto (que puede ser sensible, p. ej. «Estado serológico»). Null → sin cambios.
     */
    public static function applySchema(?array $rule, array $resolved): array {
        if ($rule === null) {
            return $resolved;
        }
        $hidden = array_flip($rule['hidden']);
        foreach (['labels', 'options'] as $k) {
            if (isset($resolved[$k]) && is_array($resolved[$k])) {
                $resolved[$k] = array_diff_key($resolved[$k], $hidden);
            }
        }
        if (isset($resolved['multi']) && is_array($resolved['multi'])) {
            $resolved['multi'] = array_values(array_filter(
                $resolved['multi'],
                fn($p) => !isset($hidden[$p])
            ));
        }
        return $resolved;
    }

    /**
     * Aplica el ocultado a un payload ya decodificado y lo devuelve LIMPIO:
     *   1) quita cada clave de datos oculta,
     *   2) filtra `_attachments` para descartar los adjuntos de campos ocultos,
     *   3) si algún campo geográfico está oculto, quita `_geolocation` (que duplica
     *      el geopoint principal) para que la ubicación no se filtre por el respaldo.
     * Con regla null devuelve el payload sin tocar. Punto único de verdad: cualquier
     * lectura para un usuario/enlace restringido pasa por aquí antes de calcular
     * derived/geo/adjuntos o de devolver `data`.
     */
    public static function apply(?array $rule, array $payload, ?array $schema = null): array {
        if ($rule === null) {
            return $payload;
        }
        $hidden = $rule['hidden'];
        foreach ($hidden as $k) {
            unset($payload[$k]);
        }

        // Adjuntos de campos ocultos: fuera (el proxy/galería no debe exponerlos).
        if (isset($payload['_attachments']) && is_array($payload['_attachments'])) {
            $hiddenMap = array_flip($hidden);
            $payload['_attachments'] = array_values(array_filter(
                $payload['_attachments'],
                function ($a) use ($hiddenMap) {
                    $field = is_array($a) ? ($a['question_xpath'] ?? null) : null;
                    return $field === null || !isset($hiddenMap[$field]);
                }
            ));
        }

        // Geolocalización: si se oculta un campo geo del esquema, también el respaldo
        // `_geolocation` (Kobo lo deriva del geopoint principal y filtraría la posición).
        if ($schema !== null && array_intersect($hidden, Geo::geoFieldPaths($schema))) {
            unset($payload['_geolocation']);
        }

        return $payload;
    }
}
