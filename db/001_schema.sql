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
    last_synced_at      DATETIME,
    -- Marca de la primera/última sincronización de ENVÍOS (la pone SubmissionSync).
    -- NULL = el formulario se descubrió pero aún no se han traído sus envíos → la UI
    -- muestra «Sin sincronizar» en vez de «0 envíos». `last_synced_at` no sirve para
    -- esto porque también lo fija el descubrimiento de formularios.
    submissions_synced_at DATETIME NULL,
    -- Override por formulario del estado inicial automático de revisión. NULL =
    -- hereda el ajuste global `initial_review_status`. Un status_key válido = ese
    -- estado se asigna (fila de sistema) al cachear un envío nuevo. Ver lib/ReviewStatus.
    initial_review_status VARCHAR(32) NULL,
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
-- `status` es un VARCHAR que referencia review_statuses.status_key (NO un ENUM): el
-- catálogo de estados es personalizable (built-ins + estados propios del usuario).
-- `user_id` es NULLable: las filas con autor NULL son del SISTEMA (estado inicial
-- automático fijado al sincronizar un envío nuevo; ver lib/ReviewStatus + SubmissionSync).
CREATE TABLE IF NOT EXISTS submission_reviews (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_uid  VARCHAR(100) NOT NULL,
    user_id         INT UNSIGNED NULL,
    status          VARCHAR(32) NOT NULL DEFAULT 'pending',
    comment         TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_submission (submission_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.6.1 Catálogo GLOBAL de estados de revisión (personalizable).
-- Los 4 built-in (is_builtin=1) se siembran abajo y no se pueden borrar ni cambiar
-- su `status_key`; sí se pueden relabelar/recolorear/reordenar y (salvo pending)
-- desactivar o cambiar su `is_open`. `is_open`=1 ⇒ el estado sigue requiriendo
-- acción (cuenta como NO resuelto, igual que pending/on_hold); =0 ⇒ resuelto/final.
-- `label` NULL en un built-in ⇒ el frontend usa la clave i18n review.<status_key>.
-- `color` es un TOKEN de una paleta cerrada (ver ReviewBadge / composables/reviewColors).
CREATE TABLE IF NOT EXISTS review_statuses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status_key      VARCHAR(32) NOT NULL UNIQUE,
    label           VARCHAR(64) NULL,
    color           VARCHAR(16) NOT NULL DEFAULT 'slate',
    is_open         TINYINT(1) NOT NULL DEFAULT 1,
    is_builtin      TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      INT NOT NULL DEFAULT 0,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Built-ins (idempotente). Colores alineados con el badge histórico:
-- pending=amber, on_hold=sky, approved=green, rejected=red.
INSERT INTO review_statuses (status_key, label, color, is_open, is_builtin, sort_order, active) VALUES
    ('pending',  NULL, 'amber', 1, 1,  0, 1),
    ('on_hold',  NULL, 'sky',   1, 1, 10, 1),
    ('approved', NULL, 'green', 0, 1, 20, 1),
    ('rejected', NULL, 'red',   0, 1, 30, 1)
ON DUPLICATE KEY UPDATE status_key = status_key;

-- 3.7 Permisos usuario-formulario
CREATE TABLE IF NOT EXISTS user_form_permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED NOT NULL,
    can_view        TINYINT(1) DEFAULT 1,
    can_edit        TINYINT(1) DEFAULT 0,
    can_validate    TINYINT(1) DEFAULT 0,
    -- Permisos a nivel de columna: campos OCULTOS a este viewer en este formulario.
    -- {"hidden":["clave","g_a/region"]} o NULL = ve todos los campos. Ver lib/FieldScope.
    -- (El scoping por filas `row_filter` lo añade db/007_row_scope.sql.)
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
