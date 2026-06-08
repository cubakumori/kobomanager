-- KoboManager — Scoping por filas (qué envíos ve un viewer dentro de un formulario)
-- Aplicar con: mysql kobomanager < db/007_row_scope.sql

-- Filtro opcional por (usuario, formulario): restringe el conjunto de envíos
-- visibles/editables/validables sin tocar las capacidades (can_view/edit/validate).
-- NULL = sin restricción (ve todos los envíos del formulario, comportamiento previo).
-- Objeto JSON con grupos a 2 niveles (AND/OR + operadores); ver lib/RowScope:
--   { "match":"all|any",
--     "groups":[ { "match":"all|any",
--       "conditions":[ {"field":"<clave>","op":"in|nin|lt|lte|gt|gte|empty|not_empty|has_any|has_all|has_none","values":[...] } ] } ] }
-- Semántica: `all`=AND, `any`=OR; un envío es visible si la regla evalúa a verdadero.
-- Fail-closed: `in` con `values` vacío no deja pasar la fila. Se sigue leyendo el
-- formato antiguo `{conditions:[{field,values}]}` (solo-AND, op implícito `in`).
ALTER TABLE user_form_permissions
    ADD COLUMN IF NOT EXISTS row_filter JSON NULL AFTER can_validate;
