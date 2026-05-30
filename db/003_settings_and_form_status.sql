-- KoboManager — Configuración global + estado de despliegue de formularios
-- Aplicar con: mysql kobomanager < db/003_settings_and_form_status.sql

-- Ajustes globales clave/valor (p. ej. qué estados de Kobo se sincronizan).
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(64) PRIMARY KEY,
    `value`     TEXT,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Por defecto: solo formularios desplegados.
INSERT INTO settings (`key`, `value`) VALUES ('sync_deployment_statuses', '["deployed"]')
    ON DUPLICATE KEY UPDATE `key` = `key`;

-- Estado de despliegue de cada formulario (deployed/draft/archived), tal como en Kobo.
ALTER TABLE forms ADD COLUMN IF NOT EXISTS deployment_status VARCHAR(20) NULL AFTER server_url;
