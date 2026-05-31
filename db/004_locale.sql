-- KoboManager — Idioma por usuario + idioma por defecto global
-- Aplicar con: mysql kobomanager < db/004_locale.sql

-- Idioma preferido del usuario (NULL = usar el idioma por defecto del sistema).
ALTER TABLE users ADD COLUMN IF NOT EXISTS locale VARCHAR(5) NULL AFTER role;

-- Idioma por defecto de la app (lo elige el administrador en Configuración).
INSERT INTO settings (`key`, `value`) VALUES ('default_locale', '"es"')
    ON DUPLICATE KEY UPDATE `key` = `key`;
