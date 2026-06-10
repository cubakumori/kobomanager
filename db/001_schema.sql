-- KoboManager — Esquema inicial (Fase 0)
-- Motor: MySQL / MariaDB
-- Aplicar con: mysql kobomanager < db/001_schema.sql

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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    sync_status         ENUM('pending', 'success', 'error') DEFAULT 'pending',
    last_sync_error     TEXT,
    active              TINYINT(1) DEFAULT 1,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (kobo_account_id) REFERENCES kobo_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_account_asset (kobo_account_id, kobo_asset_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.5 Caché de envíos
CREATE TABLE IF NOT EXISTS submissions_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id         INT UNSIGNED NOT NULL,
    submission_uid  VARCHAR(100) NOT NULL UNIQUE,
    json_payload    JSON NOT NULL,
    -- Proyección en texto plano de los VALORES de respuesta (sin claves ni
    -- metadatos `_*`), poblada por la app (lib/SubmissionSearch::textFor) en cada
    -- sync. Indexada con FULLTEXT para la búsqueda de la tabla de envíos; evita el
    -- `LIKE` sobre el JSON completo. Backfill: cli/rebuild_search_text.php.
    search_text     MEDIUMTEXT,
    submitted_at    DATETIME,
    last_synced_at  DATETIME,
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    INDEX idx_form_submitted (form_id, submitted_at),
    FULLTEXT INDEX idx_search_text (search_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.6 Revisiones internas (desacopladas de Kobo)
CREATE TABLE IF NOT EXISTS submission_reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_uid  VARCHAR(100) NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    status          ENUM('pending', 'approved', 'on_hold', 'rejected') NOT NULL DEFAULT 'pending',
    comment         TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_form (user_id, form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.8 Configuración de notificaciones
CREATE TABLE IF NOT EXISTS notification_config (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED NOT NULL,
    daily_summary   TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.9 Registro de auditoría
CREATE TABLE IF NOT EXISTS audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED,
    submission_uid  VARCHAR(100),
    action          VARCHAR(50) NOT NULL,
    detail          JSON,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
