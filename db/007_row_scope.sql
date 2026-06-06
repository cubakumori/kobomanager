-- KoboManager — Scoping por filas (qué envíos ve un viewer dentro de un formulario)
-- Aplicar con: mysql kobomanager < db/007_row_scope.sql

-- Filtro opcional por (usuario, formulario): restringe el conjunto de envíos
-- visibles/editables/validables sin tocar las capacidades (can_view/edit/validate).
-- NULL = sin restricción (ve todos los envíos del formulario, comportamiento previo).
-- Objeto JSON con la forma:
--   { "conditions": [ { "field": "<clave de envío>", "values": ["a","b"] }, ... ] }
-- Semántica: un envío es visible si, para CADA condición (AND), el valor de `field`
-- pertenece a `values` (IN). Una condición con `values` vacío no deja pasar ninguna fila.
ALTER TABLE user_form_permissions
    ADD COLUMN IF NOT EXISTS row_filter JSON NULL AFTER can_validate;
