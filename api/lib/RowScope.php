<?php
/**
 * Scoping por filas: restringe QUÉ envíos de un formulario ve/edita/valida un
 * viewer (o un enlace compartido), según un filtro configurable por el admin y
 * guardado en `user_form_permissions.row_filter` / `share_links.row_filter` (JSON).
 *
 * ── Forma canónica del filtro (grupos a 2 niveles, DNF/CNF según conectores) ──
 *   {
 *     "match": "all" | "any",            // conector ENTRE grupos (raíz)
 *     "groups": [
 *       {
 *         "match": "all" | "any",        // conector DENTRO del grupo
 *         "conditions": [
 *           { "field": "<clave>", "op": "<op>", "values": [ ... ] }
 *         ]
 *       }
 *     ]
 *   }
 *
 * Permite expresar p. ej. «(region=Norte Y equipo∈{1,2}) O (region=Sur Y edad≥18)».
 * La profundidad es fija (raíz → grupos → condiciones); no hay anidamiento mayor.
 *
 * Operadores (`op`) por condición:
 *   - `in`        valor ∈ values            (igualdad multi-valor; OP POR DEFECTO)
 *   - `nin`       valor ∉ values            (≠; un valor AUSENTE cuenta como «no está» → incluido)
 *   - `lt/lte/gt/gte`  comparación de rango contra values[0]
 *                 → numérica si el operando es numérico (con guarda), lexical si no (fechas ISO)
 *   - `empty`     valor ausente / nulo / vacío
 *   - `not_empty` valor presente y no vacío
 *   - `has_any`   select_multiple: comparte ≥1 código con values  (códigos separados por espacios)
 *   - `has_all`   select_multiple: contiene TODOS los códigos de values
 *   - `has_none`  select_multiple: no contiene NINGÚN código de values
 *
 * Semántica de conectores: `all` = AND, `any` = OR.
 *
 * Fail-closed:
 *   - NULL / sin grupos             → sin restricción (ve todo el formulario).
 *   - `in` con `values` vacío       → condición imposible (no deja pasar la fila);
 *                                      es el sentinela histórico de «filtro sin valores».
 *   - operador de valor desconocido → se trata como `in` (y por tanto fail-closed si va sin valores).
 *   - cualquier otro operador de valor con `values` vacío → se descarta (no-op).
 *
 * Retrocompatibilidad: el formato anterior `{conditions:[{field,values}]}` (solo-AND,
 * `op` implícito `in`) se sigue leyendo: `normalize()` lo envuelve en un único grupo
 * `all`. No se reescriben datos en BD; al re-guardar desde la UI se persiste el nuevo
 * formato.
 *
 * `field` es la clave tal como aparece en el JSON del envío (hoja `region` o ruta de
 * grupo `g_a/region`); se admiten metadatos de Kobo (`_submitted_by`, `username`).
 *
 * Los administradores no tienen restricción (bypass). Esta clase NO depende del esquema
 * del formulario: opera solo sobre el payload / la columna JSON, así que SQL y PHP
 * comparten exactamente la misma semántica (se blinda con tests de paridad).
 */
class RowScope {

    /** Operadores que requieren al menos un valor en `values`. */
    private const VALUE_OPS = ['in', 'nin', 'lt', 'lte', 'gt', 'gte', 'has_any', 'has_all', 'has_none'];
    /** Comparadores de rango (un solo operando). */
    private const RANGE_OPS = ['lt', 'lte', 'gt', 'gte'];
    /** Operadores de conjunto sobre select_multiple. */
    private const SET_OPS = ['has_any', 'has_all', 'has_none'];
    /** Operadores que no llevan valores. */
    private const NOVAL_OPS = ['empty', 'not_empty'];

    /** Patrón de número (entero o decimal con signo). Mismo criterio en SQL (REGEXP) y PHP. */
    private const NUM_RE_SQL = '^-?[0-9]+(\\.[0-9]+)?$';
    private const NUM_RE_PHP = '/^-?[0-9]+(\\.[0-9]+)?$/';

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
     * Normaliza un filtro crudo (de BD o enviado por el admin) a la forma canónica
     * `{match, groups:[{match, conditions:[{field, op, values}]}]}`, o null si no hay
     * condiciones útiles. Acepta también el formato anterior `{conditions:[...]}`.
     */
    public static function normalize($raw): ?array {
        if (!is_array($raw)) {
            return null;
        }
        // Retrocompat: formato viejo {conditions:[...]} (solo-AND, op implícito 'in').
        // Si trae un `match` explícito (formato no canónico), se respeta para el grupo.
        if (isset($raw['conditions']) && !isset($raw['groups'])) {
            $g = self::normGroup(['match' => self::matchOf($raw), 'conditions' => $raw['conditions']]);
            return $g ? ['match' => 'all', 'groups' => [$g]] : null;
        }
        $groupsRaw = $raw['groups'] ?? null;
        if (!is_array($groupsRaw)) {
            return null;
        }
        $groups = [];
        foreach ($groupsRaw as $g) {
            $ng = self::normGroup($g);
            if ($ng) {
                $groups[] = $ng;
            }
        }
        return $groups ? ['match' => self::matchOf($raw), 'groups' => $groups] : null;
    }

    /** Normaliza un grupo, o null si se queda sin condiciones útiles. */
    private static function normGroup($g): ?array {
        if (!is_array($g)) {
            return null;
        }
        $condsRaw = $g['conditions'] ?? null;
        if (!is_array($condsRaw)) {
            return null;
        }
        $conds = [];
        foreach ($condsRaw as $c) {
            $nc = is_array($c) ? self::normLeaf($c) : null;
            if ($nc) {
                $conds[] = $nc;
            }
        }
        return $conds ? ['match' => self::matchOf($g), 'conditions' => $conds] : null;
    }

    /** Normaliza una condición hoja, o null si no aporta nada. */
    private static function normLeaf(array $c): ?array {
        $field = isset($c['field']) ? trim((string) $c['field']) : '';
        if ($field === '') {
            return null;
        }
        $op = isset($c['op']) ? (string) $c['op'] : 'in';
        if (!in_array($op, self::VALUE_OPS, true) && !in_array($op, self::NOVAL_OPS, true)) {
            $op = 'in'; // operador desconocido → 'in' (fail-closed si va sin valores)
        }
        if (in_array($op, self::NOVAL_OPS, true)) {
            return ['field' => $field, 'op' => $op, 'values' => []];
        }
        // Operadores de valor.
        $values = $c['values'] ?? [];
        if (!is_array($values)) {
            $values = [];
        }
        $values = array_values(array_unique(array_map(fn($v) => (string) $v, $values)));
        if (in_array($op, self::RANGE_OPS, true)) {
            // Un solo operando, no vacío.
            $values = array_values(array_filter($values, fn($v) => $v !== ''));
            $values = $values ? [$values[0]] : [];
        }
        if (!$values) {
            // Sin valores utilizables: 'in' se conserva como sentinela fail-closed; el
            // resto de operadores de valor son no-op y se descartan.
            return $op === 'in' ? ['field' => $field, 'op' => 'in', 'values' => []] : null;
        }
        return ['field' => $field, 'op' => $op, 'values' => $values];
    }

    /** Conector normalizado de un nodo: 'any' explícito, 'all' por defecto. */
    private static function matchOf(array $node): string {
        return (($node['match'] ?? 'all') === 'any') ? 'any' : 'all';
    }

    /**
     * Campos referenciados por una regla NORMALIZADA (únicos, en orden de aparición).
     * Lo usa el filtro avanzado de la tabla para vetar condiciones sobre campos
     * ocultos al usuario (FieldScope) antes de ejecutar nada.
     */
    public static function fields(?array $rule): array {
        $out = [];
        foreach (($rule['groups'] ?? []) as $g) {
            foreach (($g['conditions'] ?? []) as $c) {
                $f = (string) ($c['field'] ?? '');
                if ($f !== '' && !in_array($f, $out, true)) {
                    $out[] = $f;
                }
            }
        }
        return $out;
    }

    /**
     * Regla NORMALIZADA que restringe por una selección de equipos (valores de un
     * campo de tipo «equipo»). `$keys` = claves seleccionadas; el bucket «sin equipo»
     * usa el centinela '__none__' (→ operador `empty`). Devuelve null si no hay campo o
     * la selección es null (= todos, sin restricción). Una selección VACÍA produce una
     * regla fail-closed (no casa nada). Compartida por las estadísticas (alcance fijo de
     * un enlace) y por el filtro por equipos.
     */
    public static function teamRule(?string $field, ?array $keys): ?array {
        if ($field === null || $field === '' || !is_array($keys)) {
            return null;
        }
        $sel   = array_values(array_unique(array_map('strval', $keys)));
        $codes = array_values(array_filter($sel, fn($k) => $k !== '__none__'));
        $none  = in_array('__none__', $sel, true);

        $conds = [];
        if ($codes) $conds[] = ['field' => $field, 'op' => 'in', 'values' => $codes];
        if ($none)  $conds[] = ['field' => $field, 'op' => 'empty'];

        // Nada seleccionado → fail-closed (un `in` sin valores no casa nada).
        if (!$conds) {
            $conds[] = ['field' => $field, 'op' => 'in', 'values' => []];
        }
        // Un solo grupo: las condiciones se unen con OR ('any') cuando hay códigos Y el
        // bucket «sin equipo»; con una sola condición el conector es indiferente.
        return self::normalize([
            'match'  => 'all',
            'groups' => [['match' => 'any', 'conditions' => $conds]],
        ]);
    }

    // ───────────────────────── Traducción a SQL ─────────────────────────

    /**
     * Condición SQL (+ parámetros) para aplicar el filtro sobre una columna JSON.
     * Devuelve `['1=1', []]` si no hay restricción. Los grupos se unen con el conector
     * raíz; dentro de cada grupo, las condiciones con el conector del grupo. Cada
     * condición traduce a un fragmento sobre `JSON_EXTRACT($jsonCol, ...)`.
     */
    public static function sqlCondition(?array $rule, string $jsonCol): array {
        if ($rule === null) {
            return ['1=1', []];
        }
        // Defensa: si llega el formato viejo sin normalizar, normalízalo.
        if (isset($rule['conditions']) && !isset($rule['groups'])) {
            $rule = self::normalize($rule);
            if ($rule === null) {
                return ['1=1', []];
            }
        }
        $parts = [];
        $params = [];
        foreach ($rule['groups'] as $g) {
            [$frag, $p] = self::groupSql($g, $jsonCol);
            $parts[] = $frag;
            foreach ($p as $x) {
                $params[] = $x;
            }
        }
        if (!$parts) {
            return ['1=1', []];
        }
        $glue = $rule['match'] === 'any' ? ' OR ' : ' AND ';
        return ['(' . implode($glue, $parts) . ')', $params];
    }

    /** SQL de un grupo (conjunción/disyunción de sus condiciones). */
    private static function groupSql(array $g, string $col): array {
        $parts = [];
        $params = [];
        foreach ($g['conditions'] as $c) {
            [$frag, $p] = self::leafSql($c, $col);
            $parts[] = $frag;
            foreach ($p as $x) {
                $params[] = $x;
            }
        }
        if (!$parts) {
            return ['1=1', []];
        }
        $glue = $g['match'] === 'any' ? ' OR ' : ' AND ';
        return ['(' . implode($glue, $parts) . ')', $params];
    }

    /** SQL de una condición hoja. */
    private static function leafSql(array $c, string $col): array {
        $path = self::jsonPath($c['field']);
        $ex   = "JSON_UNQUOTE(JSON_EXTRACT($col, ?))"; // consume UN parámetro de ruta por uso
        $op   = $c['op'];
        $vals = $c['values'];

        switch ($op) {
            case 'in':
                if (!$vals) {
                    return ['1=0', []]; // fail-closed
                }
                $ph = implode(',', array_fill(0, count($vals), '?'));
                return ["$ex IN ($ph)", array_merge([$path], $vals)];

            case 'nin':
                $ph = implode(',', array_fill(0, count($vals), '?'));
                return ["($ex IS NULL OR $ex NOT IN ($ph))", array_merge([$path, $path], $vals)];

            case 'empty':
                return ["($ex IS NULL OR $ex = '')", [$path, $path]];

            case 'not_empty':
                return ["($ex IS NOT NULL AND $ex <> '')", [$path, $path]];

            case 'lt':
            case 'lte':
            case 'gt':
            case 'gte':
                $sym     = ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='][$op];
                $operand = $vals[0];
                if (is_numeric($operand)) {
                    // Comparación numérica: guarda REGEXP para que solo comparen los
                    // valores realmente numéricos (un texto no-numérico → no casa, igual que en PHP).
                    return [
                        "($ex REGEXP ? AND CAST($ex AS DECIMAL(38,10)) $sym CAST(? AS DECIMAL(38,10)))",
                        [$path, self::NUM_RE_SQL, $path, $operand],
                    ];
                }
                // Comparación lexical (fechas ISO YYYY-MM-DD / texto).
                return ["($ex IS NOT NULL AND $ex $sym ?)", [$path, $path, $operand]];

            case 'has_any':
            case 'has_all':
            case 'has_none':
                $exp    = "CONCAT(' ', COALESCE($ex, ''), ' ')"; // códigos enmarcados con espacios
                $likes  = [];
                $params = [];
                foreach ($vals as $v) {
                    $likes[]  = "$exp LIKE ?";
                    $params[] = $path; // para el $ex dentro de $exp
                    $params[] = '% ' . self::likeEscape($v) . ' %';
                }
                if ($op === 'has_all') {
                    return ['(' . implode(' AND ', $likes) . ')', $params];
                }
                $orFrag = '(' . implode(' OR ', $likes) . ')';
                return $op === 'has_any' ? [$orFrag, $params] : ["NOT $orFrag", $params];
        }
        return ['1=0', []]; // defensivo (no debería alcanzarse)
    }

    // ───────────────────────── Evaluación en PHP ─────────────────────────

    /** ¿El envío (payload ya decodificado) cumple el filtro? null → siempre true. */
    public static function matches(?array $rule, array $payload): bool {
        if ($rule === null) {
            return true;
        }
        if (isset($rule['conditions']) && !isset($rule['groups'])) {
            $rule = self::normalize($rule);
            if ($rule === null) {
                return true;
            }
        }
        $results = [];
        foreach ($rule['groups'] as $g) {
            $results[] = self::groupMatches($g, $payload);
        }
        return $rule['match'] === 'any'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    /** ¿El payload cumple un grupo? */
    private static function groupMatches(array $g, array $payload): bool {
        $res = [];
        foreach ($g['conditions'] as $c) {
            $res[] = self::leafMatches($c, $payload);
        }
        return $g['match'] === 'any'
            ? in_array(true, $res, true)
            : !in_array(false, $res, true);
    }

    /** ¿El payload cumple una condición hoja? */
    private static function leafMatches(array $c, array $payload): bool {
        $op   = $c['op'];
        $vals = $c['values'];
        $raw  = $payload[$c['field']] ?? null;

        switch ($op) {
            case 'in':
                if (!$vals) {
                    return false; // fail-closed
                }
                if (!is_scalar($raw)) {
                    return false; // ausente o array (select_multiple no se consulta con 'in')
                }
                return in_array((string) $raw, $vals, true);

            case 'nin':
                if (!is_scalar($raw)) {
                    return true; // ausente → «no está en el conjunto» → incluido
                }
                return !in_array((string) $raw, $vals, true);

            case 'empty':
                return self::isEmptyVal($raw);

            case 'not_empty':
                return !self::isEmptyVal($raw);

            case 'lt':
            case 'lte':
            case 'gt':
            case 'gte':
                if (!is_scalar($raw)) {
                    return false;
                }
                $operand = $vals[0];
                $val     = (string) $raw;
                if (is_numeric($operand)) {
                    if (!preg_match(self::NUM_RE_PHP, $val)) {
                        return false; // paridad con la guarda REGEXP del SQL
                    }
                    return self::cmp((float) $val, (float) $operand, $op);
                }
                return self::cmp(strcmp($val, (string) $operand), 0, $op);

            case 'has_any':
            case 'has_all':
            case 'has_none':
                $set = array_flip(self::codes($raw));
                if ($op === 'has_all') {
                    foreach ($vals as $v) {
                        if (!isset($set[$v])) {
                            return false;
                        }
                    }
                    return true;
                }
                $hit = false;
                foreach ($vals as $v) {
                    if (isset($set[$v])) {
                        $hit = true;
                        break;
                    }
                }
                return $op === 'has_any' ? $hit : !$hit;
        }
        return false; // defensivo
    }

    /** Compara dos números (o un strcmp contra 0) según el operador de rango. */
    private static function cmp(float|int $a, float|int $b, string $op): bool {
        return match ($op) {
            'lt'  => $a < $b,
            'lte' => $a <= $b,
            'gt'  => $a > $b,
            'gte' => $a >= $b,
            default => false,
        };
    }

    /** ¿El valor cuenta como vacío? (null, array vacío o cadena en blanco). */
    private static function isEmptyVal($raw): bool {
        if ($raw === null) {
            return true;
        }
        if (is_array($raw)) {
            return count($raw) === 0;
        }
        return trim((string) $raw) === '';
    }

    /** Códigos de un select_multiple (string «a b c» → ['a','b','c']). */
    private static function codes($raw): array {
        if (is_array($raw)) {
            return array_map('strval', $raw); // por si llegara ya como array
        }
        if (!is_scalar($raw)) {
            return [];
        }
        $s = trim((string) $raw);
        return $s === '' ? [] : (preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** Escapa los comodines de LIKE (`%`, `_`) y la barra invertida. */
    private static function likeEscape(string $s): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
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
