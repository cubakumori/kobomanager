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
 * Esta clase es la FUENTE ÚNICA de «qué columnas Y TABLAS espera el código»: la usan
 * el comando `cli/doctor.php` (informe), `cli/migrate.php` (aplica lo que falte) y el
 * aviso de admin (banner en la app). Cada vez que una versión añade una columna o una
 * tabla, se añade aquí una entrada (convención documentada en CONTRIBUTING.md).
 *
 * Solo cubre lo AÑADIDO tras la primera versión pública (1.0.0): el resto del esquema
 * vive siempre en una instalación nueva, así que solo puede faltar en una BD que se
 * actualiza. No parsea SQL (frágil); compara contra `information_schema`.
 */
class SchemaCheck {

    /**
     * Tablas COMPLETAS que el código requiere y que se añadieron tras 1.0.0. El `fix`
     * es el CREATE TABLE canónico ÍNTEGRO (copia de db/001_schema.sql; si aquel
     * cambia, esta copia debe cambiar con él — lo vigila SchemaCheckTest contra la BD
     * de test). `column` va a null para que los consumidores distingan tabla de columna.
     */
    public const TABLE_CHECKS = [
        ['table' => 'contact_messages', 'column' => null, 'since' => '1.3.0',
         'fix' => "CREATE TABLE IF NOT EXISTS contact_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    org         VARCHAR(160) NULL,
    topic       VARCHAR(32)  NOT NULL DEFAULT 'general',
    message     TEXT NOT NULL,
    ip          VARCHAR(45)  NULL,
    emailed     TINYINT(1)   NOT NULL DEFAULT 0,
    status      VARCHAR(16)  NOT NULL DEFAULT 'new',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

        ['table' => 'push_subscriptions', 'column' => null, 'since' => '1.30.0',
         'fix' => "CREATE TABLE IF NOT EXISTS push_subscriptions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    endpoint      TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL UNIQUE,
    p256dh        VARCHAR(255) NOT NULL,
    auth          VARCHAR(64)  NOT NULL,
    ua_label      VARCHAR(160) NULL,
    failed_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME NULL,
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_push_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

        ['table' => 'sample_targets', 'column' => null, 'since' => '1.32.0',
         'fix' => "CREATE TABLE IF NOT EXISTS sample_targets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id       INT UNSIGNED NOT NULL,
    team_value    VARCHAR(255) NOT NULL,
    sample_value  VARCHAR(255) NOT NULL,
    target        INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sample_targets_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    UNIQUE KEY unique_sample_cell (form_id, team_value, sample_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

        ['table' => 'sample_target_history', 'column' => null, 'since' => '1.32.0',
         'fix' => "CREATE TABLE IF NOT EXISTS sample_target_history (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id       INT UNSIGNED NOT NULL,
    snapshot_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    payload_json  JSON NOT NULL,
    CONSTRAINT fk_sample_history_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    INDEX idx_sample_history_form (form_id, snapshot_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],

        ['table' => 'user_form_favorites', 'column' => null, 'since' => '1.37.0',
         'fix' => "CREATE TABLE IF NOT EXISTS user_form_favorites (
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, form_id),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fav_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"],
    ];

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

        // Umbrales del control de calidad (minutos; NULL = comprobación desactivada).
        // El DEFAULT rellena las filas existentes al aplicar el ALTER (mismo valor que
        // recibe una instalación nueva).
        ['table' => 'forms', 'column' => 'qc_min_duration', 'since' => '1.14.0',
         'fix' => "ALTER TABLE forms ADD COLUMN qc_min_duration INT UNSIGNED NULL DEFAULT 4 AFTER stats_enumerator_field"],
        ['table' => 'forms', 'column' => 'qc_max_duration', 'since' => '1.14.0',
         'fix' => "ALTER TABLE forms ADD COLUMN qc_max_duration INT UNSIGNED NULL DEFAULT NULL AFTER qc_min_duration"],
        ['table' => 'forms', 'column' => 'qc_min_gap', 'since' => '1.14.0',
         'fix' => "ALTER TABLE forms ADD COLUMN qc_min_gap INT UNSIGNED NULL DEFAULT 4 AFTER qc_max_duration"],

        // Permiso «Ajustes» por formulario (editar desglose por equipo + umbrales QC sin ser admin).
        ['table' => 'user_form_permissions', 'column' => 'can_settings', 'since' => '1.15.0',
         'fix' => "ALTER TABLE user_form_permissions ADD COLUMN can_settings TINYINT(1) DEFAULT 0 AFTER can_validate"],

        // Estado de revisión vigente desnormalizado + columnas materializadas del
        // payload (ver los comentarios del CREATE canónico). `backfill` = sentencia
        // que puebla la columna en una BD que se actualiza (migrate.php la ejecuta
        // tras el ALTER); las materializadas del payload se rellenan además con el
        // recálculo PHP de migrate.php (SubmissionSync::recomputeCacheColumns).
        ['table' => 'submissions_cache', 'column' => 'review_status', 'since' => '1.21.0',
         'fix' => "ALTER TABLE submissions_cache ADD COLUMN review_status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER kobo_validation_seen, ADD INDEX idx_form_review (form_id, review_status)",
         'backfill' => "UPDATE submissions_cache sc
            JOIN (SELECT r.submission_uid, r.status FROM submission_reviews r
                  JOIN (SELECT submission_uid, MAX(id) AS mid FROM submission_reviews GROUP BY submission_uid) m
                    ON m.mid = r.id) lr ON lr.submission_uid = sc.submission_uid
            SET sc.review_status = lr.status"],
        ['table' => 'submissions_cache', 'column' => 'kobo_id', 'since' => '1.21.0',
         'fix' => "ALTER TABLE submissions_cache ADD COLUMN kobo_id BIGINT UNSIGNED NULL AFTER review_status, ADD INDEX idx_form_kobo (form_id, kobo_id)"],
        ['table' => 'submissions_cache', 'column' => 'duration_s', 'since' => '1.21.0',
         'fix' => "ALTER TABLE submissions_cache ADD COLUMN duration_s INT UNSIGNED NULL AFTER kobo_id"],
        ['table' => 'submissions_cache', 'column' => 'att_count', 'since' => '1.21.0',
         'fix' => "ALTER TABLE submissions_cache ADD COLUMN att_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_s"],
        ['table' => 'submissions_cache', 'column' => 'has_geo', 'since' => '1.21.0',
         'fix' => "ALTER TABLE submissions_cache ADD COLUMN has_geo TINYINT(1) NOT NULL DEFAULT 0 AFTER att_count"],

        // Linaje de ediciones indexado + índices de consulta/purga del registro de
        // auditoría (antes: full scan del log por cada eslabón del historial).
        ['table' => 'audit_log', 'column' => 'edit_new_uid', 'since' => '1.21.0',
         'fix' => "ALTER TABLE audit_log
            ADD COLUMN edit_new_uid VARCHAR(100) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(detail, '\$.new_uid'))) STORED,
            ADD INDEX idx_audit_form_action (form_id, action, id),
            ADD INDEX idx_audit_created (created_at),
            ADD INDEX idx_audit_lineage (form_id, edit_new_uid)"],

        // Nº de envíos cacheado por formulario (evita un COUNT por formulario en cada
        // carga del listado). El backfill lo deja al día; después lo refresca cada sync.
        ['table' => 'forms', 'column' => 'submission_count', 'since' => '1.21.0',
         'fix' => "ALTER TABLE forms ADD COLUMN submission_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER submissions_synced_at",
         'backfill' => "UPDATE forms f SET f.submission_count =
            (SELECT COUNT(*) FROM submissions_cache sc WHERE sc.form_id = f.id)"],

        // Sensibilidad de la señal de duplicados del control de calidad (respuestas
        // mínimas de contenido; NULL = señal desactivada). El DEFAULT 2 activa la
        // señal con la sensibilidad de fábrica en las filas existentes.
        ['table' => 'forms', 'column' => 'qc_dup_min_answers', 'since' => '1.22.0',
         'fix' => "ALTER TABLE forms ADD COLUMN qc_dup_min_answers INT UNSIGNED NULL DEFAULT 2 AFTER qc_min_gap"],

        // Interruptor opt-in del índice de riesgo (N mínimo por encuestador/equipo;
        // NULL = índice desactivado). Sin DEFAULT distinto de NULL: heredar «vacío» ya es
        // el comportamiento deseado en las filas existentes (el índice no se activa solo).
        ['table' => 'forms', 'column' => 'risk_min_n', 'since' => '1.23.0',
         'fix' => "ALTER TABLE forms ADD COLUMN risk_min_n INT UNSIGNED NULL DEFAULT NULL AFTER qc_dup_min_answers"],

        // Resumen de revisión por equipo/encuestador en enlaces compartidos. Opt-in por
        // enlace con DEFAULT 0: la vista pública oculta el estado de revisión a propósito
        // (share_stats va con includeReview=false), así que los enlaces existentes no
        // deben empezar a exponerlo solos.
        ['table' => 'share_links', 'column' => 'expose_review_summary', 'since' => '1.27.0',
         'fix' => "ALTER TABLE share_links ADD COLUMN expose_review_summary TINYINT(1) NOT NULL DEFAULT 0 AFTER expose_attachments"],

        // Frecuencia de aviso por email (off|daily|hourly|every_sync; NULL = usa el
        // default global). Sustituye al binario `daily_summary`, que se conserva en
        // BDs que se actualizan (el código ya no lo lee) y el backfill lo traduce:
        // 1 → 'daily', 0 → 'off'. En una instalación nueva la columna vieja no existe
        // y este check nunca dispara (frequency viene del CREATE canónico).
        ['table' => 'notification_config', 'column' => 'frequency', 'since' => '1.29.0',
         'fix' => "ALTER TABLE notification_config ADD COLUMN frequency VARCHAR(12) NULL DEFAULT NULL AFTER form_id",
         'backfill' => "UPDATE notification_config SET frequency = CASE WHEN daily_summary = 1 THEN 'daily' ELSE 'off' END"],
        ['table' => 'notification_config', 'column' => 'last_notified_at', 'since' => '1.29.0',
         'fix' => "ALTER TABLE notification_config ADD COLUMN last_notified_at DATETIME NULL AFTER frequency"],

        // Monitorización de muestra por equipo. El select_one de muestreo (principal +
        // dos secundarios) y el denominador de «hecho». NULL = apagado; el DEFAULT
        // 'approved' del denominador es inocuo (solo importa con sample_field configurado).
        ['table' => 'forms', 'column' => 'sample_field', 'since' => '1.32.0',
         'fix' => "ALTER TABLE forms ADD COLUMN sample_field VARCHAR(255) NULL AFTER risk_min_n"],
        ['table' => 'forms', 'column' => 'sample_field2', 'since' => '1.32.0',
         'fix' => "ALTER TABLE forms ADD COLUMN sample_field2 VARCHAR(255) NULL AFTER sample_field"],
        ['table' => 'forms', 'column' => 'sample_field3', 'since' => '1.32.0',
         'fix' => "ALTER TABLE forms ADD COLUMN sample_field3 VARCHAR(255) NULL AFTER sample_field2"],
        ['table' => 'forms', 'column' => 'sample_denominator', 'since' => '1.32.0',
         'fix' => "ALTER TABLE forms ADD COLUMN sample_denominator VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER sample_field3"],

        // Permiso «Muestra» por formulario (editar el plan de muestra; implica «Ajustes»,
        // normalizado por la API al guardar). El backfill hereda el permiso a quienes ya
        // tenían «Ajustes»: hasta 1.33.x el plan se editaba con can_settings y nadie debe
        // perder esa capacidad al actualizar (el admin restringe después si quiere).
        ['table' => 'user_form_permissions', 'column' => 'can_sample', 'since' => '1.34.0',
         'fix' => "ALTER TABLE user_form_permissions ADD COLUMN can_sample TINYINT(1) DEFAULT 0 AFTER can_settings",
         'backfill' => "UPDATE user_form_permissions SET can_sample = can_settings"],

        // Preferencias de interfaz por usuario (hoy: filtros persistidos de «Mis
        // formularios»). NULL = ninguna; sin backfill (empezar vacío ya es correcto).
        ['table' => 'users', 'column' => 'ui_prefs', 'since' => '1.37.0',
         'fix' => "ALTER TABLE users ADD COLUMN ui_prefs JSON NULL AFTER locale"],

        // Agrupación de equipos bajo un «meta-equipo» (roll-up de presentación en
        // Muestra y Estadísticas). NULL = sin agrupación; sin backfill.
        ['table' => 'forms', 'column' => 'team_group_field', 'since' => '1.38.0',
         'fix' => "ALTER TABLE forms ADD COLUMN team_group_field VARCHAR(255) NULL AFTER stats_enumerator_field"],

        // Retención de envíos en caché por formulario (días sobre submitted_at;
        // NULL = conservar para siempre). Sin backfill.
        ['table' => 'forms', 'column' => 'retention_days', 'since' => '1.40.0',
         'fix' => "ALTER TABLE forms ADD COLUMN retention_days INT UNSIGNED NULL AFTER sample_denominator"],

        // Segundo factor TOTP (secreto cifrado con TokenVault, marca de activación,
        // hashes de códigos de recuperación y anti-replay). NULL = sin 2FA; sin backfill.
        ['table' => 'users', 'column' => 'totp_secret', 'since' => '1.39.0',
         'fix' => "ALTER TABLE users ADD COLUMN totp_secret TEXT NULL AFTER ui_prefs"],
        ['table' => 'users', 'column' => 'totp_enabled_at', 'since' => '1.39.0',
         'fix' => "ALTER TABLE users ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret"],
        ['table' => 'users', 'column' => 'totp_recovery_codes', 'since' => '1.39.0',
         'fix' => "ALTER TABLE users ADD COLUMN totp_recovery_codes JSON NULL AFTER totp_enabled_at"],
        ['table' => 'users', 'column' => 'totp_last_step', 'since' => '1.39.0',
         'fix' => "ALTER TABLE users ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_recovery_codes"],

        // Panel de muestra por equipo en enlaces compartidos. Opt-in por enlace con
        // DEFAULT 0 (como expose_review_summary): expone cumplimiento agregado
        // hecho/objetivo, y su denominador revela recuentos agregados de revisión,
        // así que los enlaces existentes no deben empezar a exponerlo solos.
        ['table' => 'share_links', 'column' => 'expose_sample', 'since' => '1.45.0',
         'fix' => "ALTER TABLE share_links ADD COLUMN expose_sample TINYINT(1) NOT NULL DEFAULT 0 AFTER expose_review_summary"],
    ];

    /** Columnas cuyo backfill requiere el recálculo PHP de migrate.php (no basta SQL). */
    public const RECOMPUTE_COLUMNS = ['kobo_id', 'duration_s', 'att_count', 'has_geo'];

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
        // Tablas presentes = prefijos "tabla" de las claves "tabla.columna" (toda
        // tabla tiene al menos una columna en information_schema.COLUMNS).
        $tables = [];
        foreach ($have as $key => $unused) {
            $tables[strstr($key, '.', true)] = true;
        }

        $missing = [];
        // Tablas ausentes primero: su fix crea la tabla entera de una vez.
        foreach (self::TABLE_CHECKS as $chk) {
            if (!isset($tables[$chk['table']])) {
                $missing[] = $chk;
            }
        }
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
