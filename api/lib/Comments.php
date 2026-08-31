<?php
/**
 * Panel de comentarios de revisión por formulario.
 *
 * Reúne los comentarios que ya viven en `submission_reviews` (columna `comment`;
 * una fila por CAMBIO de estado, así que un envío puede tener varios) y los agrupa
 * por equipo → encuestador con el mismo par de campos configurable del desglose de
 * estadísticas (`stats_team_field` / `stats_enumerator_field`), igual que lib/Stats
 * y lib/Quality. Sirve para leer de un vistazo qué se ha comentado en un formulario
 * sin abrir envío por envío.
 *
 * Incluye tanto los comentarios hechos en la app (`source='app'`, con su autor)
 * como los importados de Kobo por el sync (`source='kobo'`, sin usuario). Solo
 * cuentan las revisiones con comentario NO vacío.
 *
 * Es solo lectura y respeta RowScope/FieldScope como el resto: un comentario solo
 * aparece si su envío está en el RowScope del usuario (JOIN con submissions_cache,
 * que además ata el comentario a ESTE formulario), y el nivel de equipo se oculta
 * si el campo de equipo está vetado por FieldScope (mismo gating que lib/Quality).
 * El texto del comentario no es un campo del formulario, así que FieldScope no lo
 * censura. Los comentarios de envíos borrados de la caché no aparecen (INNER JOIN).
 */
class Comments {

    /**
     * @param int         $formId    Formulario.
     * @param array|null  $schemaRaw Esquema XLSForm normalizado (forms.schema_json) o null.
     * @param array|null  $scope     Regla RowScope ya normalizada (o null = sin restricción).
     * @param array|null  $fieldScope Regla FieldScope ya normalizada (o null).
     * @param string      $locale    Idioma para resolver etiquetas de equipo/encuestador.
     * @param string|null $teamField forms.stats_team_field (NULL = sin nivel de equipo).
     * @param string|null $enumField forms.stats_enumerator_field (NULL/`_submitted_by` =
     *                               usar el usuario Kobo que envió).
     * @param string|null $statusFilter Solo comentarios cuyo estado de revisión sea este
     *                               (uno de ValidationStatus::STATUSES; NULL = todos).
     * @param string|null $search    Filtro de texto sobre el comentario (subcadena; NULL = todo).
     */
    public static function compute(
        int $formId,
        ?array $schemaRaw,
        ?array $scope,
        ?array $fieldScope,
        string $locale,
        ?string $teamField,
        ?string $enumField,
        ?string $statusFilter = null,
        ?string $search = null
    ): array {
        [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'sc.json_payload');

        // Mismo gating que lib/Stats/Quality: no se agrupa por un campo oculto.
        $teamField = ($teamField !== null && $teamField !== '' && !FieldScope::isHidden($fieldScope, $teamField))
            ? $teamField : null;
        $enumIsField = $enumField !== null && $enumField !== '' && $enumField !== '_submitted_by'
            && !FieldScope::isHidden($fieldScope, $enumField);
        $enumPath = $enumIsField ? $enumField : '_submitted_by';

        // Etiquetas legibles de equipo/encuestador (según el modo de etiquetas global).
        $resolved   = FormSchema::resolve($schemaRaw, $locale);
        $labelsOn   = Settings::labelMode() === 'labels';
        $labels     = $resolved['labels'] ?? [];
        $options    = $resolved['options'] ?? [];
        $teamOptMap = $teamField !== null ? ($options[$teamField] ?? []) : [];
        $enumOptMap = $enumIsField ? ($options[$enumPath] ?? []) : [];

        $where  = "sc.form_id = ? AND r.comment IS NOT NULL AND r.comment <> '' AND $scopeSql";
        $params = array_merge([$formId], $scopeP);
        if ($statusFilter !== null && in_array($statusFilter, ValidationStatus::STATUSES, true)) {
            $where    .= ' AND r.status = ?';
            $params[]  = $statusFilter;
        }
        if ($search !== null && $search !== '') {
            $where    .= ' AND r.comment LIKE ?';
            $params[]  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
        }

        // Pasada en streaming: por cada comentario, resolver equipo/encuestador de su
        // envío y agrupar. Orden global por fecha desc (el más reciente primero); la
        // agrupación preserva ese orden dentro de cada encuestador.
        $groups = []; // tKey => eKey => [comentarios]
        $names  = []; // tKey|eKey => nombre legible (primero visto)
        $total  = 0;
        foreach (DB::stream(
            "SELECT r.id, r.submission_uid, r.status, r.comment, r.source, r.created_at,
                    u.name AS author, sc.json_payload
             FROM submission_reviews r
             JOIN submissions_cache sc ON sc.submission_uid = r.submission_uid AND sc.form_id = ?
                  AND (r.form_id = sc.form_id OR r.form_id IS NULL)
             LEFT JOIN users u ON u.id = r.user_id
             WHERE $where
             ORDER BY r.created_at DESC, r.id DESC",
            array_merge([$formId], $params)
        ) as $r) {
            $total++;
            $payload = json_decode($r['json_payload'], true) ?: [];
            $tv = $teamField !== null ? ($payload[$teamField] ?? null) : null;
            $ev = $payload[$enumPath] ?? null;
            $tKey = ($tv === null || $tv === '' || is_array($tv)) ? '—' : (string) $tv;
            $eKey = ($ev === null || $ev === '' || is_array($ev)) ? '—' : (string) $ev;

            $groups[$tKey][$eKey][] = [
                'id'            => (int) $r['id'],
                'uid'           => $r['submission_uid'],
                'review_status' => in_array($r['status'], ValidationStatus::STATUSES, true) ? $r['status'] : 'pending',
                'source'        => $r['source'],
                'author'        => $r['author'], // NULL en comentarios de Kobo (sin usuario)
                'created_at'    => $r['created_at'],
                'comment'       => $r['comment'],
            ];
        }

        // Construir la salida agrupada. Encuestadores con más comentarios primero
        // (a igualdad, alfabético por nombre); equipos igual.
        $teams = [];
        foreach ($groups as $tKey => $enums) {
            $teamName = $tKey === '—' ? '—'
                : (($labelsOn && isset($teamOptMap[$tKey])) ? $teamOptMap[$tKey] : $tKey);
            $enumOut = [];
            $teamCount = 0;
            foreach ($enums as $eKey => $comments) {
                $enumOut[] = [
                    'name'     => $eKey === '—' ? '—'
                        : (($labelsOn && isset($enumOptMap[$eKey])) ? $enumOptMap[$eKey] : $eKey),
                    'count'    => count($comments),
                    'comments' => $comments,
                ];
                $teamCount += count($comments);
            }
            usort($enumOut, fn($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);
            $teams[] = ['name' => $teamName, 'count' => $teamCount, 'enumerators' => $enumOut];
        }
        usort($teams, fn($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return [
            'total'      => $total,
            'teams'      => $teams,
            'timezone'   => Derived::tzMeta(),
            'label_mode' => Settings::labelMode(),
            // NULL = sin nivel de equipo (la UI muestra un único grupo de encuestadores).
            'team_field' => $teamField !== null ? [
                'key'   => $teamField,
                'label' => $labelsOn ? ($labels[$teamField] ?? $teamField) : $teamField,
            ] : null,
            'enumerator_field' => [
                'key'   => $enumPath,
                'label' => $enumIsField ? ($labelsOn ? ($labels[$enumPath] ?? $enumPath) : $enumPath) : null,
            ],
        ];
    }
}
