-- KoboManager — Mensajes del formulario de contacto público (página «Apoyar»)
-- Aplicar con: mysql kobomanager < db/009_contact_messages.sql
--
-- El formulario de la página /apoyar es público (sin sesión). Cada envío se
-- guarda aquí como fuente de verdad —para no perder mensajes aunque el envío de
-- email esté caído o el dominio de Resend aún no esté verificado— y, además, se
-- intenta una notificación por email best-effort a CONTACT_TO (ver api/v1/public/contact.php).
CREATE TABLE IF NOT EXISTS contact_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    org         VARCHAR(160) NULL,                       -- organización (opcional)
    topic       VARCHAR(32)  NOT NULL DEFAULT 'general', -- general|hire|proposal|using
    message     TEXT NOT NULL,
    ip          VARCHAR(45)  NULL,
    emailed     TINYINT(1)   NOT NULL DEFAULT 0,         -- 1 si la notificación por email salió
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
