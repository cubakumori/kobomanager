<?php
/**
 * Comprobación de desfase entre el ESQUEMA de la base de datos y el CÓDIGO.
 *
 * KoboManager aplica el esquema completo (`db/001_schema.sql`) de una vez sobre una
 * BD vacía y NO tiene migraciones incrementales por archivo. Al subir de versión, el
 * operador debe aplicar a mano las columnas nuevas (ver la «Nota de actualización
 * (esquema)» de cada versión en CHANGELOG.md). Si se despliega código nuevo sobre una
 * BD vieja, las consultas fallan con «Unknown column …» (un 500 opaco).
 *
 * Esta clase es la FUENTE ÚNICA de «qué columnas espera el código»: la usan el
 * comando `cli/doctor.php` (informe), `cli/migrate.php` (aplica lo que falte) y el
 * aviso de admin (banner en la app). Cada vez que una versión añade una columna, se
 * añade aquí una entrada (convención documentada en CONTRIBUTING.md).
 *
 * Solo cubre lo AÑADIDO tras la primera versión pública (1.0.0): el resto del esquema
 * vive siempre en una instalación nueva, así que solo puede faltar en una BD que se
 * actualiza. No parsea SQL (frágil); compara contra `information_schema`.
 */
class SchemaCheck {

    /**
     * Columnas que el código requiere y que se añadieron tras 1.0.0. Cada entrada:
     *   - table/column : identificador.
     *   - since        : versión que la introdujo (informativo).
     *   - nullable     : (opcional) si true, además exige que la columna admita NULL
     *                    (caso `submission_reviews.user_id`); detecta el MODIFY pendiente.
     *   - fix          : sentencia ALTER idempotente que la deja como el esquema canónico.
     *                    Válida en MySQL 5.7+ y MariaDB (sin `IF NOT EXISTS`).
     */
    public const CHECKS = [
        ['table' => 'share_links', 'column' => 'expose_stats', 'since' => '1.5.0',
         'fix' => "ALTER TABLE share_links ADD COLUMN expose_stats TINYINT(1) NOT NULL DEFAULT 0 AFTER expose_map"],

        ['table' => 'forms', 'column' => 'stats_team_field', 'since' => '1.6.0',
         'fix' => "ALTER TABLE forms ADD COLUMN stats_team_field VARCHAR(255) NULL"],
        ['table' => 'forms', 'column' => 'stats_enumerator_field', 'since' => '1.6.0',
         'fix' => "ALTER TABLE forms ADD COLUMN stats_enumerator_field VARCHAR(255) NULL"],

        ['table' => 'submissions_cache', 'column' => 'kobo_validation_seen', 'since' => '1.7.0',
         'fix' => "ALTER TABLE submissions_cache ADD COLUMN kobo_validation_seen VARCHAR(40) NULL AFTER json_payload"],
        ['table' => 'submission_reviews', 'column' => 'source', 'since' => '1.7.0',
         'fix' => "ALTER TABLE submission_reviews ADD COLUMN source ENUM('app','kobo') NOT NULL DEFAULT 'app' AFTER user_id"],
        ['table' => 'submission_reviews', 'column' => 'user_id', 'since' => '1.7.0', 'nullable' => true,
         'fix' => "ALTER TABLE submission_reviews MODIFY user_id INT UNSIGNED NULL"],
        ['table' => 'share_links', 'column' => 'team_filter', 'since' => '1.7.0',
         'fix' => "ALTER TABLE share_links ADD COLUMN team_filter JSON NULL AFTER field_filter"],
        ['table' => 'share_links', 'column' => 'stats_status', 'since' => '1.7.0',
         'fix' => "ALTER TABLE share_links ADD COLUMN stats_status VARCHAR(16) NULL AFTER team_filter"],
    ];

    /**
     * Devuelve las entradas de CHECKS que la BD actual NO satisface (columna ausente o,
     * con `nullable`, columna que no admite NULL). Una sola consulta a information_schema.
     *
     * @return array<int,array> Subconjunto de CHECKS (vacío = esquema al día).
     */
    public static function missing(): array {
        $rows = DB::run(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, IS_NULLABLE AS n
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()"
        )->fetchAll();

        $have = []; // "tabla.columna" => 'YES'|'NO'  (ausencia de clave = columna inexistente)
        foreach ($rows as $r) {
            $have[$r['t'] . '.' . $r['c']] = strtoupper((string) $r['n']);
        }
        return self::missingAgainst($have);
    }

    /**
     * Lógica pura: dado el mapa de columnas presentes en la BD ("tabla.columna" =>
     * 'YES'|'NO' de IS_NULLABLE), devuelve los CHECKS no satisfechos. Separado de la
     * consulta para poder testearlo sin tocar el esquema.
     *
     * @param array<string,string> $have
     * @return array<int,array>
     */
    public static function missingAgainst(array $have): array {
        $missing = [];
        foreach (self::CHECKS as $chk) {
            $key = $chk['table'] . '.' . $chk['column'];
            if (!array_key_exists($key, $have)) {
                $missing[] = $chk; // columna inexistente
                continue;
            }
            if (!empty($chk['nullable']) && $have[$key] !== 'YES') {
                $missing[] = $chk; // existe pero debería admitir NULL
            }
        }
        return $missing;
    }

    /** ¿Está el esquema al día respecto al código? */
    public static function isUpToDate(): bool {
        return self::missing() === [];
    }
}
