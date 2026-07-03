<?php
/**
 * Semilla y reset de la DEMO gestionados por la app (sin mysqldump ni SQL a mano).
 *
 * `export()` vuelca el estado de la instancia a `DEMO_SEED_PATH` como SQL
 * SOLO-DATOS (INSERT multi-fila con ids y lista de columnas explícitos; nada de
 * DDL) y `restore()` lo devuelve a la BD en UNA transacción InnoDB. Lo llaman
 * el endpoint admin `POST /admin/demo/seed` (con la demo APAGADA) y el cron
 * `cron/demo_reset.php` (con la demo ENCENDIDA). Ver DEMO.md.
 *
 * Decisiones de formato (por qué solo-datos):
 *   - `db/001_schema.sql` sigue siendo la única fuente del esquema; una semilla
 *     vieja sobrevive a columnas nuevas (el INSERT lista sus columnas y lo nuevo
 *     toma su DEFAULT).
 *   - Sin DDL no hay commits implícitos → el restore cabe en una transacción:
 *     los visitantes ven la instantánea previa hasta el COMMIT y un fallo a
 *     mitad hace ROLLBACK (la demo nunca queda medio vacía). Por eso DELETE y
 *     no TRUNCATE (TRUNCATE es DDL); los AUTO_INCREMENT no se reinician, las
 *     filas semilla recuperan su id explícito.
 *   - Lo generamos nosotros → portable MySQL 5.7+/MariaDB por construcción
 *     (sin línea sandbox de MariaDB ni comentarios condicionales), y sigue
 *     siendo importable a mano con mysql/phpMyAdmin en una emergencia.
 *
 * Privacidad por formato: las tablas efímeras (sesiones, IPs, auditoría,
 * mensajes de contacto) NUNCA entran en la semilla — sustituye a los TRUNCATE
 * manuales del antiguo runbook.
 */
class DemoSeed {

    /** Tablas que viajan en la semilla y se restauran en cada reset (orden padre→hijo,
     *  por si alguien importa el archivo a mano con FK checks activos). */
    public const SEEDED_TABLES = [
        'kobo_accounts', 'users', 'forms', 'submissions_cache', 'submission_reviews',
        'user_form_permissions', 'notification_config', 'settings', 'share_links',
    ];

    /** Tablas efímeras: nunca se exportan y el reset las vacía (rastro de visitantes). */
    public const EPHEMERAL_TABLES = ['audit_log', 'login_attempts', 'rate_hits', 'password_resets'];

    /**
     * Intocables: ni se exportan ni el reset las toca.
     *   - user_sessions: se CONSERVA entre resets para no desloguear a los visitantes a
     *     mitad de clic (los ids de `users` se restauran idénticos y en demo nadie puede
     *     crear/borrar usuarios, así que las sesiones vivas siguen siendo válidas; las
     *     huérfanas se purgan como cinturón al final del restore).
     *   - contact_messages: mensajes REALES de visitantes interesados — persisten hasta
     *     que el operador los lea con la demo apagada.
     */
    public const UNTOUCHED_TABLES = ['user_sessions', 'contact_messages'];

    /** Claves de `settings` que NO viajan en la semilla y que el reset preserva:
     *  telemetría de crons (/health) y la marca del propio ciclo de reset. Si se
     *  restauraran, cada reset resucitaría marcas de ejecución fósiles. */
    public const VOLATILE_SETTINGS = ['cron_runs', 'demo_last_reset_at'];

    /** Primera línea del archivo; el restore se niega a tocar la BD sin ella. */
    private const HEADER = '-- kobomanager-demo-seed v1';

    /** Tamaño objetivo por sentencia INSERT multi-fila (muy por debajo de max_allowed_packet). */
    private const CHUNK_BYTES = 500_000;

    /** Ruta configurada de la semilla ('' = no configurada). */
    public static function path(): string {
        return defined('DEMO_SEED_PATH') ? (string) DEMO_SEED_PATH : '';
    }

    /** Estado para la UI de admin: ¿hay ruta configurada? ¿existe ya una semilla? */
    public static function status(): array {
        $path   = self::path();
        $exists = $path !== '' && is_file($path);
        return [
            'configured'   => $path !== '',
            'path'         => $path,
            'exists'       => $exists,
            'bytes'        => $exists ? (int) filesize($path) : null,
            // ISO-8601 UTC para que el frontend lo formatee en hora local.
            'generated_at' => $exists ? gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($path)) : null,
        ];
    }

    /**
     * Exporta las tablas de semilla a DEMO_SEED_PATH (escritura atómica: .tmp + rename).
     * Instantánea consistente (equivalente a mysqldump --single-transaction).
     *
     * @return array{tables:int, rows:int, bytes:int, path:string}
     * @throws RuntimeException si la ruta no está configurada o no se puede escribir.
     */
    public static function export(): array {
        $path = self::path();
        if ($path === '') {
            throw new RuntimeException('DEMO_SEED_PATH no está configurado en config.php.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException("El directorio de DEMO_SEED_PATH no existe o no es escribible: $dir");
        }

        $pdo = DB::conn();
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $sql  = self::HEADER . ' | generado ' . gmdate('Y-m-d H:i:s') . " UTC\n";
            $sql .= "-- Solo datos (INSERT); el esquema vive en db/001_schema.sql. Restaurable con\n";
            $sql .= "-- cron/demo_reset.php o, en emergencia, a mano: mysql <bd> < seed.sql\n";
            $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n";

            $totalRows = 0;
            foreach (self::SEEDED_TABLES as $table) {
                $query = "SELECT * FROM `$table`";
                if ($table === 'settings') {
                    $ph = implode(',', array_fill(0, count(self::VOLATILE_SETTINGS), '?'));
                    $query .= " WHERE `key` NOT IN ($ph)";
                    $stmt = DB::run($query, self::VOLATILE_SETTINGS);
                } else {
                    $stmt = DB::run($query);
                }
                $totalRows += self::appendInserts($sql, $pdo, $table, $stmt);
            }
            $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        } finally {
            $pdo->exec('COMMIT'); // solo lecturas: cierra la instantánea
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $sql) === false) {
            throw new RuntimeException("No se pudo escribir $tmp");
        }
        @chmod($tmp, 0640); // contiene hashes de contraseña y el token Kobo cifrado
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("No se pudo mover la semilla a $path");
        }

        return [
            'tables' => count(self::SEEDED_TABLES),
            'rows'   => $totalRows,
            'bytes'  => strlen($sql),
            'path'   => $path,
        ];
    }

    /**
     * Restaura la BD desde la semilla en UNA transacción: valida el archivo
     * (cabecera + solo INSERT en tablas de semilla), vacía las tablas de semilla
     * y las efímeras con DELETE, re-inserta, purga sesiones huérfanas y COMMIT.
     * Cualquier fallo → ROLLBACK y la demo conserva el estado anterior.
     *
     * @return array{statements:int, rows:int}
     * @throws RuntimeException si la semilla falta o no valida.
     */
    public static function restore(): array {
        $path = self::path();
        if ($path === '') {
            throw new RuntimeException('DEMO_SEED_PATH no está configurado en config.php.');
        }
        if (!is_file($path)) {
            throw new RuntimeException("No existe la semilla: $path (genérala con la demo apagada).");
        }
        $sql = (string) file_get_contents($path);
        if (!str_starts_with($sql, self::HEADER)) {
            throw new RuntimeException("El archivo $path no es una semilla de KoboManager (cabecera ausente).");
        }

        // Validar ANTES de tocar la BD: solo SET de sesión e INSERT en tablas de
        // semilla. El splitter copia los comentarios verbatim (van pegados a la
        // sentencia siguiente), así que se descartan solo para VALIDAR.
        $stmts = SqlScript::split($sql);
        $clean = static fn(string $s): string => trim((string) preg_replace('/^\s*--[^\n]*$/m', '', $s));
        foreach ($stmts as $stmt) {
            $s = $clean($stmt);
            if (preg_match('/^SET\s/i', $s)) continue;
            if (preg_match('/^INSERT\s+INTO\s+`?(\w+)`?\s/i', $s, $m)
                && in_array($m[1], self::SEEDED_TABLES, true)) continue;
            throw new RuntimeException('Sentencia no permitida en la semilla: ' . substr($s, 0, 80) . '…');
        }

        $pdo = DB::conn();
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            // Con FK checks off los DELETE no disparan cascadas: borrado explícito y
            // determinista (y no arrasa user_sessions por la cascada de users).
            foreach (self::SEEDED_TABLES as $table) {
                if ($table === 'settings') {
                    // Preservar la telemetría de crons y la marca del reset.
                    $ph = implode(',', array_fill(0, count(self::VOLATILE_SETTINGS), '?'));
                    DB::run("DELETE FROM settings WHERE `key` NOT IN ($ph)", self::VOLATILE_SETTINGS);
                } else {
                    $pdo->exec("DELETE FROM `$table`");
                }
            }
            foreach (self::EPHEMERAL_TABLES as $table) {
                $pdo->exec("DELETE FROM `$table`");
            }

            $rows = 0;
            foreach ($stmts as $stmt) {
                $n = $pdo->exec($stmt);
                if (preg_match('/^INSERT\s/i', $clean($stmt))) {
                    $rows += (int) $n;
                }
            }

            // Cinturón: sesiones de usuarios que ya no existen en la semilla.
            $pdo->exec('DELETE FROM user_sessions WHERE user_id NOT IN (SELECT id FROM users)');
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

    /** Añade a $sql los INSERT multi-fila de una tabla, troceados por bytes. */
    private static function appendInserts(string &$sql, PDO $pdo, string $table, PDOStatement $stmt): int {
        $rows = 0;
        $head = '';
        $buf  = '';
        while (($row = $stmt->fetch()) !== false) {
            if ($head === '') {
                $cols = array_map(static fn($c) => "`$c`", array_keys($row));
                $head = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES\n";
            }
            $vals = array_map(static fn($v) => self::sqlValue($pdo, $v), array_values($row));
            $line = '(' . implode(', ', $vals) . ')';
            $buf .= ($buf === '' ? '' : ",\n") . $line;
            $rows++;
            if (strlen($buf) >= self::CHUNK_BYTES) {
                $sql .= $head . $buf . ";\n";
                $buf = '';
            }
        }
        if ($buf !== '') {
            $sql .= $head . $buf . ";\n";
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
