-- KoboManager — Enlaces de solo lectura compartibles (M1)
-- Aplicar con: mysql kobomanager < db/008_share_links.sql
--
-- Un enlace expone, sin necesidad de cuenta Kobo ni de sesión en KoboManager,
-- una vista de solo lectura de un formulario: lista de envíos, detalle y/o mapa.
-- Reutiliza el scoping por filas (`row_filter`, misma forma que
-- `user_form_permissions.row_filter`) para que un enlace muestre solo un
-- subconjunto de envíos. Acceso por token impredecible en la URL; opcionalmente
-- protegido con contraseña (ver ajuste `share_password_policy`).
--
-- Los adjuntos (`expose_attachments`) se sirven por un proxy público dedicado y
-- solo pueden exponerse si el enlace tiene contraseña y la política global
-- `share_attachments_policy` lo permite (off | require_password, en `settings`).

CREATE TABLE IF NOT EXISTS share_links (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token             VARCHAR(64) NOT NULL UNIQUE,          -- secreto en la URL (/s/<token>)
    form_id           INT UNSIGNED NOT NULL,
    created_by        INT UNSIGNED NOT NULL,                -- usuario admin que lo creó
    label             VARCHAR(255) NULL,                    -- nombre interno para el panel
    expose_list       TINYINT(1) NOT NULL DEFAULT 1,        -- mostrar lista de envíos
    expose_detail     TINYINT(1) NOT NULL DEFAULT 1,        -- permitir ver el detalle de un envío
    expose_map        TINYINT(1) NOT NULL DEFAULT 0,        -- mostrar mapa
    expose_attachments TINYINT(1) NOT NULL DEFAULT 0,       -- exponer adjuntos (solo si el enlace tiene contraseña; ver `share_attachments_policy`)
    row_filter        JSON NULL,                            -- {match,groups:[{match,conditions:[{field,op,values}]}]} o NULL (ver lib/RowScope; lee también el formato antiguo {conditions:[...]})
    field_filter      JSON NULL,                            -- {hidden:["clave",...]} o NULL: columnas ocultas en este enlace (ver lib/FieldScope)
    password_hash     VARCHAR(255) NULL,                    -- NULL = acceso solo por token
    expires_at        DATETIME NULL,                        -- NULL = sin caducidad
    revoked_at        DATETIME NULL,                        -- no NULL = revocado (deja de funcionar)
    last_accessed_at  DATETIME NULL,
    access_count      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id)    REFERENCES forms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_form (form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
