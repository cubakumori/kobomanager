<?php
/**
 * Semilla y reset de la DEMO gestionados por la app (sin mysqldump ni SQL a mano).
 *
 * Cliente de `lib/DbSnapshot` (motor común de volcado/restauración solo-datos,
 * compartido con la copia de seguridad `lib/DbBackup`): `export()` vuelca el
 * estado de la instancia a `DEMO_SEED_PATH` y `restore()` lo devuelve a la BD en
 * UNA transacción. Lo llaman el endpoint admin `POST /admin/demo/seed` (con la
 * demo APAGADA) y el cron `cron/demo_reset.php` (con la demo ENCENDIDA). Ver DEMO.md.
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

    /** Primera línea del archivo; el restore se niega a tocar la BD sin ella
     *  (y no acepta una copia de seguridad de DbBackup, que lleva otra cabecera). */
    private const HEADER = '-- kobomanager-demo-seed v1';

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

        $sql  = self::HEADER . ' | generado ' . gmdate('Y-m-d H:i:s') . " UTC\n";
        $sql .= "-- Solo datos (INSERT); el esquema vive en db/001_schema.sql. Restaurable con\n";
        $sql .= "-- cron/demo_reset.php o, en emergencia, a mano: mysql <bd> < seed.sql\n";
        $rows = DbSnapshot::dump(self::SEEDED_TABLES, function (string $chunk) use (&$sql) {
            $sql .= $chunk;
        });

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
            'rows'   => $rows,
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

        $stmts = SqlScript::split($sql);
        DbSnapshot::validate($stmts, self::SEEDED_TABLES);
        return DbSnapshot::restore(
            $stmts,
            array_merge(self::SEEDED_TABLES, self::EPHEMERAL_TABLES),
            true // purga de sesiones de usuarios fuera de la semilla
        );
    }
}
