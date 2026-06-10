-- KoboManager — Idioma por defecto global (users.locale vive en db/001_schema.sql)
-- Aplicar con: mysql kobomanager < db/004_locale.sql

-- Idioma por defecto de la app (lo elige el administrador en Configuración).
INSERT INTO settings (`key`, `value`) VALUES ('default_locale', '"es"')
    ON DUPLICATE KEY UPDATE `key` = `key`;
