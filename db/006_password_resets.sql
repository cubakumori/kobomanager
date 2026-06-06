-- KoboManager — Recuperación de contraseña por email
-- Tokens de un solo uso para el flujo «olvidé mi contraseña».
-- Aplicar con: mysql kobomanager < db/006_password_resets.sql
--
-- Nota: se guarda solo el HASH (sha256) del token, nunca el token en claro.
-- El token viaja únicamente en el email/enlace; si la BD se filtra, no es
-- utilizable. El flujo está gobernado por el ajuste `password_reset_enabled`
-- (tabla settings; desactivado por defecto).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,          -- sha256 hex del token en claro
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME DEFAULT NULL,             -- se fija al consumir el token
    ip          VARCHAR(45),                       -- IP que solicitó el reset
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
