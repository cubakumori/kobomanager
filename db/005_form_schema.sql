-- KoboManager — Caché del esquema XLSForm de cada formulario (etiquetas legibles)
-- Aplicar con: mysql kobomanager < db/005_form_schema.sql

-- Esquema normalizado del formulario (preguntas y opciones, multi-idioma) tal como
-- se descarga del contenido del asset en Kobo. Se refresca en cada sincronización.
ALTER TABLE forms ADD COLUMN IF NOT EXISTS schema_json JSON NULL AFTER deployment_status;
ALTER TABLE forms ADD COLUMN IF NOT EXISTS schema_synced_at DATETIME NULL AFTER schema_json;

-- Por defecto: mostrar las labels del formulario en tabla y detalles.
INSERT INTO settings (`key`, `value`) VALUES ('label_mode', '"labels"')
    ON DUPLICATE KEY UPDATE `key` = `key`;
