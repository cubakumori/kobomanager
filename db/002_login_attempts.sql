-- KoboManager — Fase 7: rate limiting de login
-- Aplicar con: mysql kobomanager < db/002_login_attempts.sql

CREATE TABLE IF NOT EXISTS login_attempts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting genérico por "bucket" (p. ej. lectura de enlaces públicos de share).
-- Separado de login_attempts para no cruzar el throttle de login con el de lectura.
CREATE TABLE IF NOT EXISTS rate_hits (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(32) NOT NULL,
    ip          VARCHAR(45) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bucket_ip_time (bucket, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
