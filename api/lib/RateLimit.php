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
}
