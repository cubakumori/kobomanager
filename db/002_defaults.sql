-- KoboManager — Valores por defecto de `settings`.
-- Aplicar tras db/001_schema.sql: mysql kobomanager < db/002_defaults.sql
-- Idempotente (ON DUPLICATE KEY): re-aplicarlo nunca pisa un valor ya configurado.

-- Sincronizar solo formularios desplegados.
INSERT INTO settings (`key`, `value`) VALUES ('sync_deployment_statuses', '["deployed"]')
    ON DUPLICATE KEY UPDATE `key` = `key`;

-- Idioma por defecto de la app (lo cambia el administrador en Configuración).
INSERT INTO settings (`key`, `value`) VALUES ('default_locale', '"es"')
    ON DUPLICATE KEY UPDATE `key` = `key`;

-- Mostrar etiquetas legibles del formulario en tabla y detalle.
INSERT INTO settings (`key`, `value`) VALUES ('label_mode', '"labels"')
    ON DUPLICATE KEY UPDATE `key` = `key`;
