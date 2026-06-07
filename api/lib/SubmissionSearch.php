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
    public static function textFor(array $payload): string {
        $parts = [];
        self::collect($payload, $parts);
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
}
