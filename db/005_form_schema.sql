-- KoboManager — Modo de etiquetas por defecto (forms.schema_json vive en db/001_schema.sql)
-- Aplicar con: mysql kobomanager < db/005_form_schema.sql

-- Por defecto: mostrar las labels del formulario en tabla y detalles.
INSERT INTO settings (`key`, `value`) VALUES ('label_mode', '"labels"')
    ON DUPLICATE KEY UPDATE `key` = `key`;
