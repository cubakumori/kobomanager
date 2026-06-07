<?php
/**
 * Rate limiting sencillo basado en la tabla login_attempts.
 * Cuenta intentos fallidos por IP dentro de una ventana de tiempo.
 */
class RateLimit {
    /** ¿Se ha superado el máximo de intentos en la ventana (segundos)? */
    public static function tooMany(string $ip, int $max, int $seconds): bool {
        $count = (int) DB::run(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)',
            [$ip, $seconds]
        )->fetch()['c'];
        return $count >= $max;
    }

    /** Registra un intento fallido. */
    public static function hit(string $ip): void {
        DB::run('INSERT INTO login_attempts (ip) VALUES (?)', [$ip]);
    }

    /** Limpia los intentos de una IP (p. ej. tras un login correcto). */
    public static function clear(string $ip): void {
        DB::run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    }

    // ---------- Rate limiting genérico por "bucket" (tabla rate_hits) ----------
    // No comparte tabla con login_attempts: así el throttle de lectura pública
    // (scraping/DoS de enlaces compartidos) no interfiere con el de login.

    /** ¿Se ha superado el máximo de peticiones del bucket para esa IP en la ventana? */
    public static function tooManyBucket(string $ip, string $bucket, int $max, int $seconds): bool {
        $count = (int) DB::run(
            'SELECT COUNT(*) AS c FROM rate_hits
             WHERE bucket = ? AND ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)',
            [$bucket, $ip, $seconds]
        )->fetch()['c'];
        return $count >= $max;
    }

    /** Registra una petición del bucket. Poda vieja de forma oportunista (1%). */
    public static function hitBucket(string $ip, string $bucket): void {
        DB::run('INSERT INTO rate_hits (ip, bucket) VALUES (?, ?)', [$ip, $bucket]);
        if (random_int(1, 100) === 1) {
            DB::run('DELETE FROM rate_hits WHERE created_at < (NOW() - INTERVAL 1 HOUR)');
        }
    }
}
