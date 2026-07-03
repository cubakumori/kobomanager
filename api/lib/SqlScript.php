<?php
/**
 * Utilidades para ejecutar archivos .sql desde PHP sin el cliente `mysql`.
 *
 * La usan el instalador (`cli/install.php`, aplica db/*.sql) y la semilla de la
 * demo (`lib/DemoSeed`, exporta/restaura). Una sola implementación del split.
 */
class SqlScript {

    /**
     * Divide un .sql en sentencias ejecutables. Respeta `;` dentro de cadenas e
     * identificadores Y dentro de comentarios (`-- …` hasta fin de línea y
     * bloques estilo C), que se copian verbatim (MySQL los acepta dentro de la
     * sentencia). Suficiente para el DDL canónico de db/ y para las semillas de
     * la demo (sin DELIMITER).
     *
     * @return string[] Sentencias sin el `;` final (descarta restos que sean solo comentarios).
     */
    public static function split(string $sql): array
    {
        $stmts = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null; // comilla abierta: ' " `
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\' && $quote !== '`') { $buf .= $sql[++$i] ?? ''; continue; }
                if ($ch === $quote) { $quote = null; }
                continue;
            }
            // Comentario `--` hasta fin de línea: copiar sin interpretar `;`.
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $len && $sql[$i] !== "\n") { $buf .= $sql[$i]; $i++; }
                $buf .= "\n";
                continue;
            }
            // Comentario de bloque: copiar sin interpretar `;`.
            if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $buf .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buf .= $ch; continue; }
            if ($ch === ';') {
                $stmt = trim($buf);
                // Descartar restos que sean SOLO comentarios/espacio.
                if ($stmt !== '' && trim((string) preg_replace('/^\s*--.*$/m', '', $stmt)) !== '') {
                    $stmts[] = $stmt;
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $stmt = trim($buf);
        if ($stmt !== '' && trim((string) preg_replace('/^\s*--.*$/m', '', $stmt)) !== '') {
            $stmts[] = $stmt;
        }
        return $stmts;
    }
}
