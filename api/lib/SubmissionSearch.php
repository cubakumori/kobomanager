<?php
/**
 * Búsqueda de texto sobre `submissions_cache`.
 *
 * En vez de un `LIKE` sobre el JSON completo (escaneo de toda la fila, y matchea
 * dentro de claves y metadatos), se mantiene una columna `search_text` con solo
 * los VALORES de respuesta, indexada con FULLTEXT. Esta clase concentra las dos
 * mitades de esa función:
 *   - textFor(): proyección que puebla la columna (al sincronizar / backfill).
 *   - clause():  fragmento WHERE para consultar (MATCH … AGAINST, o LIKE de
 *                respaldo para términos demasiado cortos para FULLTEXT).
 */
class SubmissionSearch {

    /**
     * Texto plano buscable de un envío: concatena los valores escalares del
     * payload, SALTÁNDOSE las claves de metadatos de Kobo (las que empiezan por
     * `_`: `_attachments`, `_geolocation`, `_validation_status`, `_id`…). Así el
     * índice no se contamina con URLs de adjuntos, UUIDs internos ni rutas de
     * campo, y una búsqueda de «audio» ya no casa con un `question_xpath`.
     */
    public static function textFor(array $payload, array $optionLabels = []): string {
        $parts = [];
        self::collect($payload, $parts);

        // Enriquecer con etiquetas legibles de las opciones (select_one/multiple): así
        // buscar «Femenino» casa un envío cuyo payload guarda el código «2». $optionLabels
        // = FormSchema::searchOptionLabels($schema): ruta => { código => "etiquetas" }.
        // Los códigos crudos ya van vía collect(), de modo que buscar por código sigue
        // funcionando (decisión: código + etiqueta).
        foreach ($optionLabels as $path => $byCode) {
            if (!array_key_exists($path, $payload)) {
                continue;
            }
            $val = $payload[$path];
            if (!is_string($val) && !is_int($val)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim((string) $val), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
                if (isset($byCode[$code])) {
                    $parts[] = $byCode[$code];
                }
            }
        }
        return trim(implode(' ', $parts));
    }

    /** Recolecta recursivamente los valores escalares, ignorando claves `_*`. */
    private static function collect(mixed $node, array &$out): void {
        if (is_array($node)) {
            $isList = array_is_list($node);
            foreach ($node as $key => $value) {
                if (!$isList && is_string($key) && str_starts_with($key, '_')) {
                    continue; // metadato de Kobo
                }
                self::collect($value, $out);
            }
            return;
        }
        if (is_bool($node) || $node === null) return;
        $s = trim((string) $node);
        if ($s !== '') $out[] = $s;
    }

    /**
     * Fragmento WHERE para buscar `$term` sobre `$alias.search_text`.
     * Devuelve [sqlFragment, params].
     *
     * Usa FULLTEXT en MODO BOOLEANO con prefijo (`+token*`) para los tokens de
     * longitud >= innodb_ft_min_token_size (3 por defecto). Si el término no tiene
     * ningún token aprovechable (p. ej. 1–2 caracteres), cae a un `LIKE` sobre la
     * misma columna (más pequeña que el JSON) para no perder esas búsquedas.
     */
    public static function clause(string $alias, string $term): array {
        $col     = $alias . '.search_text';
        $tokens  = preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $boolean = [];
        foreach ($tokens as $tok) {
            // Quitar los operadores booleanos de FULLTEXT del propio término.
            $clean = preg_replace('/[+\-><()~*"@]/', '', $tok);
            if ($clean !== null && mb_strlen($clean) >= 3) {
                $boolean[] = '+' . $clean . '*';
            }
        }
        if ($boolean) {
            return ["MATCH($col) AGAINST (? IN BOOLEAN MODE)", [implode(' ', $boolean)]];
        }
        // Respaldo para términos cortos: LIKE sobre search_text.
        return ["$col LIKE ?", ['%' . $term . '%']];
    }

    /**
     * Fragmento WHERE para buscar `$term` SOLO sobre los campos visibles `$paths`.
     * Devuelve [sqlFragment, params].
     *
     * Se usa para usuarios/enlaces con columnas ocultas (FieldScope): el índice
     * FULLTEXT global `search_text` incluye los valores de campos ocultos, de modo
     * que casar contra él filtraría que una fila CONTIENE un valor sensible aunque
     * el valor no se muestre. En su lugar, cada token debe aparecer en al menos un
     * campo visible (`LIKE` por columna; multi-palabra = AND entre tokens). Más
     * lento que FULLTEXT, pero los usuarios restringidos son minoría.
     *
     * Si no hay campos visibles, devuelve `['1=0', []]` (nada que buscar → 0 filas).
     */
    public static function clauseVisible(string $alias, string $term, array $paths): array {
        if (!$paths) {
            return ['1=0', []];
        }
        $tokens = preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$tokens) {
            return ['1=1', []];
        }
        // Rutas JSON escapadas (reutiliza el escape de barras de RowScope para grupos).
        $jsonPaths = array_map([RowScope::class, 'jsonPath'], $paths);

        // JSON_EXTRACT/JSON_UNQUOTE devuelven colación binaria (sensible a
        // mayúsculas/acentos); se fuerza utf8mb4_unicode_ci para que la búsqueda sea
        // insensible, igual que el FULLTEXT sobre search_text (buscar «maria» casa «María»).
        $andClauses = [];
        $params     = [];
        foreach ($tokens as $tok) {
            $ors = [];
            foreach ($jsonPaths as $jp) {
                $ors[]    = "JSON_UNQUOTE(JSON_EXTRACT($alias.json_payload, ?)) COLLATE utf8mb4_unicode_ci LIKE ?";
                $params[] = $jp;
                $params[] = '%' . $tok . '%';
            }
            $andClauses[] = '(' . implode(' OR ', $ors) . ')';
        }
        return ['(' . implode(' AND ', $andClauses) . ')', $params];
    }
}
