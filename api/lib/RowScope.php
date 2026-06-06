<?php
/**
 * Scoping por filas: restringe QUÉ envíos de un formulario ve/edita/valida un
 * viewer, según un filtro configurable por el admin y guardado en
 * `user_form_permissions.row_filter` (JSON).
 *
 * Forma del filtro:
 *   { "conditions": [ { "field": "<clave de envío>", "values": ["a","b"] }, ... ] }
 *
 * Semántica: un envío es visible si, para CADA condición (AND), el valor de
 * `field` en el envío pertenece a `values` (IN dentro de cada condición).
 *   - NULL / sin condiciones  → sin restricción (ve todo el formulario).
 *   - una condición con `values` vacío → no deja pasar ninguna fila (fail-closed).
 *
 * `field` es la clave tal como aparece en el JSON del envío (hoja `region` o ruta
 * de grupo `g_a/region`); se admiten metadatos de Kobo (`_submitted_by`, `username`).
 *
 * Los administradores no tienen restricción (bypass).
 */
class RowScope {

    /**
     * Regla normalizada del usuario para un formulario, o null si no hay restricción.
     * Admin → null. Viewer sin fila o con row_filter vacío → null.
     */
    public static function ruleForUser(array $user, int $formId): ?array {
        if (($user['role'] ?? '') === 'admin') {
            return null;
        }
        $row = DB::run(
            'SELECT row_filter FROM user_form_permissions WHERE user_id = ? AND form_id = ?',
            [$user['id'], $formId]
        )->fetch();
        if (!$row || $row['row_filter'] === null || $row['row_filter'] === '') {
            return null;
        }
        return self::normalize(json_decode((string) $row['row_filter'], true));
    }

    /**
     * Normaliza un filtro crudo (de BD o enviado por el admin) a
     * `{conditions: [{field, values}]}`, o null si no hay condiciones útiles.
     */
    public static function normalize($raw): ?array {
        if (!is_array($raw)) {
            return null;
        }
        $conds = $raw['conditions'] ?? null;
        if (!is_array($conds)) {
            return null;
        }
        $out = [];
        foreach ($conds as $c) {
            if (!is_array($c)) continue;
            $field = isset($c['field']) ? trim((string) $c['field']) : '';
            if ($field === '') continue;
            $values = $c['values'] ?? [];
            if (!is_array($values)) $values = [];
            $values = array_values(array_unique(array_map(fn($v) => (string) $v, $values)));
            $out[] = ['field' => $field, 'values' => $values];
        }
        return $out ? ['conditions' => $out] : null;
    }

    /**
     * Condición SQL (+ parámetros) para aplicar el filtro sobre una columna JSON.
     * Devuelve `['1=1', []]` si no hay restricción y `['1=0', []]` si el filtro no
     * puede satisfacerse (condición sin valores → fail-closed).
     */
    public static function sqlCondition(?array $rule, string $jsonCol): array {
        if ($rule === null) {
            return ['1=1', []];
        }
        $clauses = [];
        $params  = [];
        foreach ($rule['conditions'] as $c) {
            if (!$c['values']) {
                return ['1=0', []]; // condición imposible de cumplir
            }
            $placeholders = implode(',', array_fill(0, count($c['values']), '?'));
            $clauses[] = "JSON_UNQUOTE(JSON_EXTRACT($jsonCol, ?)) IN ($placeholders)";
            $params[]  = self::jsonPath($c['field']);
            foreach ($c['values'] as $v) {
                $params[] = $v;
            }
        }
        return ['(' . implode(' AND ', $clauses) . ')', $params];
    }

    /** ¿El envío (payload ya decodificado) cumple el filtro? null → siempre true. */
    public static function matches(?array $rule, array $payload): bool {
        if ($rule === null) {
            return true;
        }
        foreach ($rule['conditions'] as $c) {
            if (!$c['values']) {
                return false; // fail-closed
            }
            $val = $payload[$c['field']] ?? null;
            if (!is_scalar($val)) {
                return false; // ausente o no escalar (p. ej. select_multiple: no soportado)
            }
            if (!in_array((string) $val, $c['values'], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ruta JSON para una clave de envío: `$."clave"`.
     *
     * MariaDB almacena el JSON como texto y NO normaliza el escape `\/`, así que
     * las claves con barra (rutas de grupo `G01/P1_3`) quedan guardadas como
     * `G01\/P1_3`. Para que JSON_EXTRACT las encuentre, la barra debe ir escapada
     * en la ruta. En MySQL nativo `\/` también equivale a `/`, así que es seguro.
     */
    public static function jsonPath(string $field): string {
        $clean = str_replace(['\\', '"'], '', $field); // sanea backslashes/comillas del input
        $clean = str_replace('/', '\\/', $clean);      // escapa la barra como la guarda MariaDB
        return '$."' . $clean . '"';
    }
}
