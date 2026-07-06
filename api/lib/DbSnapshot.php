<?php
/**
 * Motor común de instantáneas SQL SOLO-DATOS de la base de datos.
 *
 * Lo comparten la semilla de la demo (`lib/DemoSeed`) y la copia de seguridad
 * (`lib/DbBackup`): volcado como INSERT multi-fila (ids y lista de columnas
 * explícitos, instantánea consistente, troceo por bytes) y restauración en UNA
 * transacción InnoDB validada. Cada consumidor pone su propia cabecera y sus
 * conjuntos de tablas; este motor no sabe de archivos ni de HTTP.
 *
 * Por qué solo-datos: `db/001_schema.sql` sigue siendo la única fuente del
 * esquema; un volcado viejo sobrevive a columnas nuevas (el INSERT lista sus
 * columnas y lo nuevo toma su DEFAULT); y sin DDL no hay commits implícitos,
 * así que el restore cabe en una transacción (DELETE y no TRUNCATE): fallo a
 * mitad → ROLLBACK. Generado por nosotros = portable MySQL 5.7+/MariaDB.
 */
class DbSnapshot {

    /** Claves de `settings` que NUNCA viajan en un volcado y que todo restore
     *  preserva: telemetría de crons (/health) y la marca del reset de la demo. */
    public const VOLATILE_SETTINGS = ['cron_runs', 'demo_last_reset_at'];

    /** Tamaño objetivo por sentencia INSERT multi-fila (muy por debajo de max_allowed_packet). */
    private const CHUNK_BYTES = 500_000;

    /**
     * Vuelca $tables como INSERTs al callback $write (streaming: nunca acumula
     * más de un chunk). Envuelve las lecturas en una instantánea consistente
     * (equivalente a mysqldump --single-transaction). En `settings` excluye las
     * claves volátiles. Devuelve el total de filas volcadas.
     *
     * @param string[] $tables
     * @param callable(string):void $write
     */
    public static function dump(array $tables, callable $write): int {
        $pdo = DB::conn();
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n");
            $rows = 0;
            foreach ($tables as $table) {
                if ($table === 'settings') {
                    $ph   = implode(',', array_fill(0, count(self::VOLATILE_SETTINGS), '?'));
                    $stmt = DB::run("SELECT * FROM `settings` WHERE `key` NOT IN ($ph)", self::VOLATILE_SETTINGS);
                } else {
                    $stmt = DB::run("SELECT * FROM `$table`");
                }
                $rows += self::emitInserts($write, $pdo, $table, $stmt);
            }
            $write("SET FOREIGN_KEY_CHECKS = 1;\n");
            return $rows;
        } finally {
            $pdo->exec('COMMIT'); // solo lecturas: cierra la instantánea
        }
    }

    /**
     * Valida que las sentencias de un volcado sean SOLO SET de sesión e INSERT
     * en tablas de la lista blanca (los comentarios viajan pegados a la sentencia
     * siguiente y se ignoran al validar). Lanza RuntimeException si algo no cuadra
     * — se llama ANTES de tocar la BD.
     *
     * @param string[] $stmts
     * @param string[] $allowedTables
     */
    public static function validate(array $stmts, array $allowedTables): void {
        foreach ($stmts as $stmt) {
            $s = self::stripComments($stmt);
            if (preg_match('/^SET\s/i', $s)) continue;
            if (preg_match('/^INSERT\s+INTO\s+`?(\w+)`?\s/i', $s, $m)
                && in_array($m[1], $allowedTables, true)) continue;
            throw new RuntimeException('Sentencia no permitida en el volcado: ' . substr($s, 0, 80) . '…');
        }
    }

    /**
     * Restaura en UNA transacción: vacía $wipeTables con DELETE (en `settings`
     * preserva las claves volátiles), ejecuta las sentencias (ya validadas) y,
     * opcionalmente, purga las sesiones de usuarios que ya no existen. Cualquier
     * fallo → ROLLBACK y la BD conserva el estado anterior.
     *
     * @param string[] $stmts     Sentencias ya pasadas por validate().
     * @param string[] $wipeTables
     * @return array{statements:int, rows:int}
     */
    public static function restore(array $stmts, array $wipeTables, bool $purgeOrphanSessions): array {
        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            // Con FK checks off los DELETE no disparan cascadas: borrado explícito
            // y determinista (no arrasa tablas vecinas por la cascada de users).
            foreach ($wipeTables as $table) {
                if ($table === 'settings') {
                    $ph = implode(',', array_fill(0, count(self::VOLATILE_SETTINGS), '?'));
                    DB::run("DELETE FROM settings WHERE `key` NOT IN ($ph)", self::VOLATILE_SETTINGS);
                } else {
                    $pdo->exec("DELETE FROM `$table`");
                }
            }

            $rows = 0;
            foreach ($stmts as $stmt) {
                $n = $pdo->exec($stmt);
                if (preg_match('/^INSERT\s/i', self::stripComments($stmt))) {
                    $rows += (int) $n;
                }
            }

            if ($purgeOrphanSessions) {
                $pdo->exec('DELETE FROM user_sessions WHERE user_id NOT IN (SELECT id FROM users)');
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();
            return ['statements' => count($stmts), 'rows' => $rows];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw $e;
        }
    }

    /** Quita las líneas de comentario `--` (el splitter las copia verbatim). */
    private static function stripComments(string $stmt): string {
        return trim((string) preg_replace('/^\s*--[^\n]*$/m', '', $stmt));
    }

    /** Emite los INSERT multi-fila de una tabla, troceados por bytes. Devuelve filas. */
    private static function emitInserts(callable $write, PDO $pdo, string $table, PDOStatement $stmt): int {
        $rows = 0;
        $head = '';
        $buf  = '';
        while (($row = $stmt->fetch()) !== false) {
            if ($head === '') {
                $cols = array_map(static fn($c) => "`$c`", array_keys($row));
                $head = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES\n";
            }
            $vals = array_map(static fn($v) => self::sqlValue($pdo, $v), array_values($row));
            $buf .= ($buf === '' ? '' : ",\n") . '(' . implode(', ', $vals) . ')';
            $rows++;
            if (strlen($buf) >= self::CHUNK_BYTES) {
                $write($head . $buf . ";\n");
                $buf = '';
            }
        }
        if ($buf !== '') {
            $write($head . $buf . ";\n");
        }
        return $rows;
    }

    /** Literal SQL de un valor de columna (NULL, número o cadena escapada por PDO). */
    private static function sqlValue(PDO $pdo, mixed $v): string {
        if ($v === null) return 'NULL';
        if (is_int($v) || is_float($v)) return (string) $v;
        return $pdo->quote((string) $v);
    }
}
