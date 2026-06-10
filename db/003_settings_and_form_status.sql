-- KoboManager — Configuración global (clave/valor)
-- Aplicar con: mysql kobomanager < db/003_settings_and_form_status.sql
-- (forms.deployment_status vive en el CREATE TABLE de db/001_schema.sql)

-- Ajustes globales clave/valor (p. ej. qué estados de Kobo se sincronizan).
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(64) PRIMARY KEY,
    `value`     TEXT,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Por defecto: solo formularios desplegados.
INSERT INTO settings (`key`, `value`) VALUES ('sync_deployment_statuses', '["deployed"]')
    ON DUPLICATE KEY UPDATE `key` = `key`;
