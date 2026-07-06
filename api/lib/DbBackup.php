<?php
/**
 * Copia de seguridad y restauración de la BD desde la app (pestaña «Base de datos»).
 *
 * Cliente de `lib/DbSnapshot` (motor común con la semilla de la demo). Dos alcances:
 *   - 'full'     → las tablas de la semilla + audit_log + contact_messages. Copia de
 *                  seguridad / migración de servidor. Quedan FUERA a propósito:
 *                  user_sessions (resucitar sesiones viejas no aporta y estorba),
 *                  login_attempts y rate_hits (ventanas de minutos) y password_resets
 *                  (restaurar un backup no debe revalidar un token de recuperación).
 *   - 'settings' → solo la tabla settings (sin claves volátiles). Sin ids: portable
 *                  entre instancias para replicar la configuración.
 *
 * El transporte es del ENDPOINT (descarga/subida por el navegador), no de esta lib.
 * La cabecera declara el alcance y distingue el archivo de una semilla de demo:
 * cada consumidor valida la suya (el cron de la demo no acepta un backup y viceversa).
 *
 * OJO operativo (documentado en DEPLOY §11): CONFIG_TOKEN_KEY vive en config.php y NO
 * viaja en el backup — restaurado en una instancia con otra clave, los tokens de Kobo
 * quedan indescifrables (re-conectar las cuentas).
 */
class DbBackup {

    public const SCOPES = ['full', 'settings'];

    /** Primera línea del archivo: `-- kobomanager-backup v1 | scope <full|settings> | …`. */
    private const HEADER = '-- kobomanager-backup v1';

    /** Tablas de cada alcance (el orden padre→hijo permite el import manual de emergencia). */
    public static function tables(string $scope): array {
        return $scope === 'settings'
            ? ['settings']
            : array_merge(DemoSeed::SEEDED_TABLES, ['audit_log', 'contact_messages']);
    }

    /**
     * Vuelca el backup al callback $write (streaming; el endpoint lo emite como
     * descarga sin acumularlo en memoria). Devuelve el total de filas.
     *
     * @param callable(string):void $write
     */
    public static function export(string $scope, callable $write): int {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new RuntimeException("Alcance de backup no válido: $scope");
        }
        $write(self::HEADER . " | scope $scope | generado " . gmdate('Y-m-d H:i:s') . " UTC\n");
        $write("-- Solo datos (INSERT); el esquema vive en db/001_schema.sql. Restaurable desde\n");
        $write("-- Configuración → Base de datos o, en emergencia, a mano: mysql <bd> < backup.sql\n");
        return DbSnapshot::dump(self::tables($scope), $write);
    }

    /**
     * Restaura un backup subido: detecta el alcance de la cabecera, valida el
     * contenido (solo INSERT en las tablas de ese alcance) ANTES de tocar la BD,
     * y restaura en UNA transacción (fallo a mitad → ROLLBACK). En alcance 'full'
     * purga las sesiones de usuarios que no estén en el backup (la del propio
     * admin incluida, si su usuario no viaja en él — se documenta en la UI).
     *
     * @return array{scope:string, statements:int, rows:int}
     * @throws RuntimeException si el archivo no es un backup válido.
     */
    public static function import(string $sql): array {
        if (!str_starts_with($sql, self::HEADER)) {
            throw new RuntimeException('El archivo no es una copia de seguridad de KoboManager (cabecera ausente).');
        }
        $firstLine = strtok($sql, "\n");
        if (!preg_match('/\|\s*scope\s+(full|settings)\s*\|/', (string) $firstLine, $m)) {
            throw new RuntimeException('La cabecera del backup no declara un alcance válido.');
        }
        $scope = $m[1];

        $stmts = SqlScript::split($sql);
        DbSnapshot::validate($stmts, self::tables($scope));
        $res = DbSnapshot::restore($stmts, self::tables($scope), $scope === 'full');
        return ['scope' => $scope] + $res;
    }
}
