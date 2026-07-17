-- KoboManager — Esquema COMPLETO (todas las tablas).
-- Motor: MySQL 5.7+ / MariaDB. Solo DDL canónico (CREATE TABLE): se aplica UNA
-- vez sobre una base de datos vacía; no hay migraciones incrementales.
-- Aplicar con: mysql kobomanager < db/001_schema.sql
-- (Los valores por defecto de `settings` van en db/002_defaults.sql.)
--
-- NOTA de portabilidad: las claves foráneas llevan NOMBRE explícito y único
-- (`fk_<tabla>_<ref>`). Sin nombre, MariaDB las autogenera como `1`, `2`… POR TABLA,
-- y un `mysqldump` de MariaDB materializa esos nombres; al importarlo en MySQL —que
-- exige nombres de constraint únicos POR BASE DE DATOS— chocan (#1826 Duplicate
-- foreign key constraint name). Con nombres propios el dump es portable a MySQL.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 3.1 Cuentas Kobo del administrador (tokens cifrados con TokenVault)
CREATE TABLE IF NOT EXISTS kobo_accounts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(100) NOT NULL,
    server_url  VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    api_token   TEXT NOT NULL,                  -- cifrado con TokenVault (libSodium)
    active      TINYINT(1) DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.2 Usuarios de la app (no de Kobo)
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin', 'viewer') NOT NULL DEFAULT 'viewer',
    -- Idioma preferido del usuario (NULL = usar el idioma por defecto del sistema).
    locale          VARCHAR(5) NULL,
    active          TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.3 Sesiones activas (jti del JWT)
CREATE TABLE IF NOT EXISTS user_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_id        VARCHAR(64) NOT NULL UNIQUE,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    last_activity   DATETIME,
    ip              VARCHAR(45),
    user_agent      TEXT,
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.4 Caché de formularios desde Kobo
CREATE TABLE IF NOT EXISTS forms (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kobo_account_id     INT UNSIGNED NOT NULL,
    kobo_asset_uid      VARCHAR(50) NOT NULL,
    name                VARCHAR(255) NOT NULL,
    server_url          VARCHAR(255) NOT NULL,
    -- Estado de despliegue en Kobo (deployed/draft/archived).
    deployment_status   VARCHAR(20) NULL,
    -- Esquema XLSForm normalizado (preguntas y opciones, multi-idioma) descargado del
    -- asset en Kobo para mostrar etiquetas legibles. Se refresca en cada sincronización.
    schema_json         JSON NULL,
    schema_synced_at    DATETIME NULL,
    last_synced_at      DATETIME,
    -- Marca de la primera/última sincronización de ENVÍOS (la pone SubmissionSync).
    -- NULL = el formulario se descubrió pero aún no se han traído sus envíos → la UI
    -- muestra «Sin sincronizar» en vez de «0 envíos». `last_synced_at` no sirve para
    -- esto porque también lo fija el descubrimiento de formularios.
    submissions_synced_at DATETIME NULL,
    -- Nº de envíos en caché (lo refresca SubmissionSync al final de cada sync).
    -- Evita un COUNT por formulario en cada carga del listado; frescura = la del sync.
    submission_count    INT UNSIGNED NOT NULL DEFAULT 0,
    -- Desglose de estadísticas «por equipo → encuestador» (opcional, por formulario).
    -- `stats_team_field`: ruta del campo del envío que identifica el EQUIPO/grupo
    --   (hoja `team` o ruta de grupo `g/team`). NULL = desglose por equipo apagado.
    -- `stats_enumerator_field`: ruta del campo que identifica al ENCUESTADOR dentro
    --   del equipo. NULL = usar `_submitted_by` (el usuario Kobo que envió).
    -- Los pone un admin desde la pantalla de ajustes del formulario; la sincronización
    -- no los toca (actualiza columnas concretas).
    stats_team_field      VARCHAR(255) NULL,
    stats_enumerator_field VARCHAR(255) NULL,
    -- Umbrales del CONTROL DE CALIDAD por equipo/encuestador (página forms/<id>/quality).
    -- En MINUTOS; NULL = comprobación desactivada. Los pone un admin desde los ajustes
    -- del formulario; la sincronización no los toca.
    -- `qc_min_duration`: duración menor admisible de una encuesta (más corta → bandera).
    -- `qc_max_duration`: duración mayor admisible (más larga → bandera). NULL = sin tope.
    -- `qc_min_gap`: hueco mínimo admisible entre el FIN de una encuesta y el INICIO de la
    --   siguiente del mismo encuestador (menor → bandera). Un hueco NEGATIVO (encuestas
    --   solapadas) se marca SIEMPRE, tenga el valor que tenga este umbral.
    -- `qc_dup_min_answers`: nº mínimo de respuestas de CONTENIDO para que un envío
    --   participe en la señal de duplicados (en RESPUESTAS, no minutos; NULL = señal
    --   desactivada). El default 2 evita que envíos casi vacíos generen ruido.
    qc_min_duration     INT UNSIGNED NULL DEFAULT 4,
    qc_max_duration     INT UNSIGNED NULL DEFAULT NULL,
    qc_min_gap          INT UNSIGNED NULL DEFAULT 4,
    qc_dup_min_answers  INT UNSIGNED NULL DEFAULT 2,
    -- ÍNDICE DE RIESGO por encuestador/equipo (detección heurística de fabricación,
    -- página forms/<id>/risk). Interruptor OPT-IN único: N mínimo de encuestas por
    -- encuestador/equipo para puntuar. NULL = índice DESACTIVADO (a diferencia de los
    -- umbrales de QC, que son inocuos de fábrica, una «puntuación de sospecha» por
    -- persona exige opt-in deliberado). Por debajo del N: «datos insuficientes» (no se
    -- puntúa). Las métricas se auto-activan según los datos disponibles (Benford solo con
    -- numéricos, GPS solo con geo, percentmatch con ≥2 envíos). Lo pone un admin desde
    -- los ajustes del formulario; la sincronización no lo toca.
    risk_min_n          INT UNSIGNED NULL DEFAULT NULL,
    sync_status         ENUM('pending', 'success', 'error') DEFAULT 'pending',
    last_sync_error     TEXT,
    active              TINYINT(1) DEFAULT 1,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_forms_account FOREIGN KEY (kobo_account_id) REFERENCES kobo_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_account_asset (kobo_account_id, kobo_asset_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.5 Caché de envíos
CREATE TABLE IF NOT EXISTS submissions_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id         INT UNSIGNED NOT NULL,
    submission_uid  VARCHAR(100) NOT NULL UNIQUE,
    json_payload    JSON NOT NULL,
    -- Último `_validation_status.uid` de Kobo observado por el sync = línea base del
    -- merge a 3 vías del estado de validación (ver lib/SubmissionSync::reconcileValidation
    -- y lib/ValidationStatus). NULL = nunca visto / sin estado.
    kobo_validation_seen VARCHAR(40) NULL,
    -- Estado de revisión VIGENTE (desnormalizado de la última fila de
    -- submission_reviews; 'pending' = sin revisión). Lo mantienen los endpoints de
    -- revisión y el pull de validación del sync (lib/ValidationStatus::recordReview);
    -- evita materializar todo el log de revisiones en cada lectura filtrada.
    review_status   VARCHAR(16) NOT NULL DEFAULT 'pending',
    -- Columnas materializadas desde json_payload por el sync (lib/Derived::cacheColumns):
    -- permiten ordenar/filtrar/agregar sin parsear el JSON de todo el formulario.
    -- Backfill en actualizaciones: php api/cli/migrate.php.
    kobo_id         BIGINT UNSIGNED NULL,                  -- `_id` numérico de Kobo (barrido de bajas)
    duration_s      INT UNSIGNED NULL,                     -- end − start (claves meta del esquema)
    att_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- nº de adjuntos
    has_geo         TINYINT(1) NOT NULL DEFAULT 0,         -- ¿tiene coordenadas?
    -- Proyección en texto plano de los VALORES de respuesta (sin claves ni
    -- metadatos `_*`), poblada por la app (lib/SubmissionSearch::textFor) en cada
    -- sync. Indexada con FULLTEXT para la búsqueda de la tabla de envíos; evita el
    -- `LIKE` sobre el JSON completo. Backfill: cli/rebuild_search_text.php.
    search_text     MEDIUMTEXT,
    submitted_at    DATETIME,
    last_synced_at  DATETIME,
    CONSTRAINT fk_submissions_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    INDEX idx_form_submitted (form_id, submitted_at),
    INDEX idx_form_review (form_id, review_status),
    INDEX idx_form_kobo (form_id, kobo_id),
    FULLTEXT INDEX idx_search_text (search_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.6 Revisiones internas, sincronizadas con el `_validation_status` nativo de Kobo
--     (push bloqueante al revisar + pull en cada sync; gana Kobo en conflicto).
--     `source` distingue el origen: 'app' = revisión hecha en KoboManager (user_id
--     NOT NULL); 'kobo' = estado traído de Kobo por el sync (user_id NULL). La regla
--     se aplica en el código (MySQL 5.7 no tiene CHECK por columnas cruzadas).
CREATE TABLE IF NOT EXISTS submission_reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_uid  VARCHAR(100) NOT NULL,
    user_id         INT UNSIGNED NULL,
    source          ENUM('app', 'kobo') NOT NULL DEFAULT 'app',
    status          ENUM('pending', 'approved', 'on_hold', 'rejected') NOT NULL DEFAULT 'pending',
    comment         TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_submission (submission_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.7 Permisos usuario-formulario
CREATE TABLE IF NOT EXISTS user_form_permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED NOT NULL,
    can_view        TINYINT(1) DEFAULT 1,
    can_edit        TINYINT(1) DEFAULT 0,
    can_validate    TINYINT(1) DEFAULT 0,
    -- «Ajustes»: puede editar los ajustes de ESTE formulario (desglose por equipo y
    -- umbrales del control de calidad) sin ser admin. No incluye borrar el formulario.
    can_settings    TINYINT(1) DEFAULT 0,
    -- Scoping por filas: restringe qué envíos ve/edita/valida este usuario en este
    -- formulario, sin tocar las capacidades. NULL = sin restricción. Objeto JSON con
    -- grupos a 2 niveles (AND/OR + operadores); ver lib/RowScope:
    --   { "match":"all|any",
    --     "groups":[ { "match":"all|any",
    --       "conditions":[ {"field":"<clave>","op":"in|nin|lt|lte|gt|gte|empty|not_empty|has_any|has_all|has_none","values":[...]} ] } ] }
    -- `all`=AND, `any`=OR. Fail-closed: `in` con `values` vacío no deja pasar la fila.
    -- Se sigue leyendo el formato antiguo {conditions:[{field,values}]} (solo-AND).
    row_filter      JSON NULL,
    -- Permisos a nivel de columna: campos OCULTOS a este viewer en este formulario.
    -- {"hidden":["clave","g_a/region"]} o NULL = ve todos los campos. Ver lib/FieldScope.
    field_filter    JSON NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ufp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ufp_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_form (user_id, form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.8 Configuración de notificaciones (una fila por usuario × formulario)
CREATE TABLE IF NOT EXISTS notification_config (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED NOT NULL,
    -- Frecuencia del aviso por email de envíos nuevos:
    --   'off' | 'daily' (resumen diario) | 'hourly' | 'every_sync' (tras cada pasada
    --   del cron de sync). NULL = sin preferencia explícita → aplica el valor por
    --   defecto global (ajuste notifications_default_frequency).
    frequency       VARCHAR(12) NULL DEFAULT NULL,
    -- Marca de agua UTC de los avisos casi inmediatos (hourly/every_sync): último
    -- `submitted_at` ya avisado. La mantiene lib/Notifier; NULL = sin línea base
    -- (el notificador la inicializa a «ahora» sin avisar, para no inundar con el
    -- histórico). El resumen diario no la usa (su ventana es el día natural).
    last_notified_at DATETIME NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.9 Registro de auditoría
CREATE TABLE IF NOT EXISTS audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED,
    submission_uid  VARCHAR(100),
    action          VARCHAR(50) NOT NULL,
    detail          JSON,
    -- `detail.new_uid` de las acciones 'edit' (columna generada e indexada): el
    -- historial de ediciones reconstruye el linaje de uuid con lookups por índice
    -- en vez de escanear el log entero por cada eslabón (v1/submissions/history.php).
    edit_new_uid    VARCHAR(100) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(detail, '$.new_uid'))) STORED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_audit_form_action (form_id, action, id),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_lineage (form_id, edit_new_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.10 Rate limiting de login (por IP)
CREATE TABLE IF NOT EXISTS login_attempts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting genérico por "bucket" (p. ej. lectura de enlaces públicos de share).
-- Separado de login_attempts para no cruzar el throttle de login con el de lectura.
CREATE TABLE IF NOT EXISTS rate_hits (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(32) NOT NULL,
    ip          VARCHAR(45) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bucket_ip_time (bucket, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.11 Configuración global clave/valor (los defaults se siembran en db/002_defaults.sql)
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(64) PRIMARY KEY,
    `value`     TEXT,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.12 Recuperación de contraseña por email (tokens de un solo uso; solo se
--      guarda el HASH sha256 del token; gobernado por `password_reset_enabled`)
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,          -- sha256 hex del token en claro
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME DEFAULT NULL,             -- se fija al consumir el token
    ip          VARCHAR(45),                       -- IP que solicitó el reset
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.13 Enlaces públicos de solo lectura (token en URL, contraseña opcional,
--      scoping por filas/columnas; ver lib/ShareLink y la cabecera histórica en git)
CREATE TABLE IF NOT EXISTS share_links (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token             VARCHAR(64) NOT NULL UNIQUE,          -- secreto en la URL (/s/<token>)
    form_id           INT UNSIGNED NOT NULL,
    created_by        INT UNSIGNED NOT NULL,                -- usuario admin que lo creó
    label             VARCHAR(255) NULL,                    -- nombre interno para el panel
    expose_list       TINYINT(1) NOT NULL DEFAULT 1,        -- mostrar lista de envíos
    expose_detail     TINYINT(1) NOT NULL DEFAULT 1,        -- permitir ver el detalle de un envío
    expose_map        TINYINT(1) NOT NULL DEFAULT 0,        -- mostrar mapa
    expose_stats      TINYINT(1) NOT NULL DEFAULT 0,        -- mostrar estadísticas (sin el estado de revisión interno)
    expose_attachments TINYINT(1) NOT NULL DEFAULT 0,       -- exponer adjuntos (solo si el enlace tiene contraseña; ver `share_attachments_policy`)
    expose_review_summary TINYINT(1) NOT NULL DEFAULT 0,    -- exponer el resumen de revisión por equipo/encuestador (opt-in: la vista pública oculta la revisión por defecto; solo con campo de equipo/encuestador)
    row_filter        JSON NULL,                            -- {match,groups:[{match,conditions:[{field,op,values}]}]} o NULL (ver lib/RowScope; lee también el formato antiguo {conditions:[...]})
    field_filter      JSON NULL,                            -- {hidden:["clave",...]} o NULL: columnas ocultas en este enlace (ver lib/FieldScope)
    team_filter       JSON NULL,                            -- ["claveEquipo",...] o NULL: alcance FIJO por equipo (valores de forms.stats_team_field; '__none__' = sin equipo). Se combina en AND con row_filter en todos los endpoints del enlace
    stats_status      VARCHAR(16) NULL,                     -- alcance por estado de revisión del enlace: NULL/'all' = todos; 'approved' = solo aprobados (aplica a lista/mapa/detalle/adjuntos/stats)
    password_hash     VARCHAR(255) NULL,                    -- NULL = acceso solo por token
    expires_at        DATETIME NULL,                        -- NULL = sin caducidad
    revoked_at        DATETIME NULL,                        -- no NULL = revocado (deja de funcionar)
    last_accessed_at  DATETIME NULL,
    access_count      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shares_form    FOREIGN KEY (form_id)    REFERENCES forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_shares_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_form (form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.14 Mensajes del formulario de contacto público (/apoyar): fuente de verdad
--      aunque el email best-effort a CONTACT_TO falle (ver api/v1/public/contact.php)
CREATE TABLE IF NOT EXISTS contact_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    org         VARCHAR(160) NULL,                       -- organización (opcional)
    topic       VARCHAR(32)  NOT NULL DEFAULT 'general', -- general|hire|proposal|using
    message     TEXT NOT NULL,
    ip          VARCHAR(45)  NULL,
    emailed     TINYINT(1)   NOT NULL DEFAULT 0,         -- 1 si la notificación por email salió
    status      VARCHAR(16)  NOT NULL DEFAULT 'new',     -- new|read|archived (bandeja admin)
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.15 Suscripciones Web Push (una fila por dispositivo/navegador con opt-in del
--      usuario; ver api/v1/push_subscriptions.php y lib/WebPush). El endpoint del
--      push service puede superar los 255 caracteres y no cabe en un índice único
--      → la unicidad va sobre su sha256 (endpoint_hash).
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    endpoint      TEXT NOT NULL,                        -- URL del push service (FCM/Mozilla/APNs web)
    endpoint_hash CHAR(64) NOT NULL UNIQUE,             -- sha256(endpoint), clave real de la fila
    p256dh        VARCHAR(255) NOT NULL,                -- clave pública P-256 del navegador (base64url)
    auth          VARCHAR(64)  NOT NULL,                -- secreto auth de la suscripción (base64url)
    ua_label      VARCHAR(160) NULL,                    -- descripción del dispositivo (best-effort, la manda el cliente)
    failed_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- fallos de envío consecutivos (poda al superar el umbral; 404/410 podan al instante)
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME NULL,                        -- último push aceptado por el push service
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_push_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
