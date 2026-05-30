<?php
/**
 * Autenticación y sesiones.
 *
 * - Login: email + contraseña → JWT (HS256) con `jti` único.
 * - El JWT viaja en cookie HttpOnly + SameSite (+ Secure en producción).
 * - Cada sesión se registra en `user_sessions`; en cada request se valida el
 *   JWT y que su `jti` siga existiendo (permite invalidación activa).
 *
 * JWT implementado a mano (HS256) para no depender de librerías externas.
 */
class Auth {

    // ---------- JWT (HS256) ----------

    private static function b64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $txt): string {
        return base64_decode(strtr($txt, '-_', '+/')) ?: '';
    }

    private static function sign(string $data): string {
        return self::b64url(hash_hmac('sha256', $data, JWT_SECRET, true));
    }

    private static function jwtEncode(array $payload): string {
        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body   = self::b64url(json_encode($payload));
        return $header . '.' . $body . '.' . self::sign($header . '.' . $body);
    }

    /** Devuelve el payload si el JWT es válido y no ha expirado; null en otro caso. */
    private static function jwtDecode(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h, $b, $sig] = $parts;
        if (!hash_equals(self::sign($h . '.' . $b), $sig)) return null;
        $payload = json_decode(self::b64urlDecode($b), true);
        if (!is_array($payload)) return null;
        if (($payload['exp'] ?? 0) < time()) return null;
        return $payload;
    }

    // ---------- Sesiones ----------

    /** Crea sesión + JWT para un usuario y fija la cookie. Devuelve el token. */
    public static function issue(array $user): string {
        $jti = bin2hex(random_bytes(16));
        $now = time();
        $exp = $now + JWT_TTL;

        DB::run(
            'INSERT INTO user_sessions (user_id, token_id, expires_at, last_activity, ip, user_agent)
             VALUES (?, ?, FROM_UNIXTIME(?), NOW(), ?, ?)',
            [
                $user['id'], $jti, $exp,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]
        );

        $jwt = self::jwtEncode(['sub' => (int) $user['id'], 'jti' => $jti, 'iat' => $now, 'exp' => $exp]);
        self::setCookie($jwt, $exp);
        return $jwt;
    }

    private static function setCookie(string $value, int $expires): void {
        setcookie(COOKIE_NAME, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'secure'   => COOKIE_SECURE,
            'samesite' => COOKIE_SAMESITE,
        ]);
    }

    private static function clearCookie(): void {
        setcookie(COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'secure'   => COOKIE_SECURE,
            'samesite' => COOKIE_SAMESITE,
        ]);
    }

    /**
     * Resuelve el usuario autenticado del request actual, o null.
     * Valida JWT + existencia de la sesión (jti) + usuario activo.
     */
    public static function currentUser(): ?array {
        $jwt = $_COOKIE[COOKIE_NAME] ?? '';
        if ($jwt === '') return null;

        $payload = self::jwtDecode($jwt);
        if ($payload === null) return null;

        $session = DB::run(
            'SELECT id FROM user_sessions WHERE token_id = ? AND expires_at > NOW()',
            [$payload['jti']]
        )->fetch();
        if (!$session) return null;

        $user = DB::run(
            'SELECT id, name, email, role, active FROM users WHERE id = ? AND active = 1',
            [$payload['sub']]
        )->fetch();
        if (!$user) return null;

        DB::run('UPDATE user_sessions SET last_activity = NOW() WHERE id = ?', [$session['id']]);

        unset($user['active']);
        $user['id']   = (int) $user['id'];
        return $user;
    }

    /** Cierra la sesión del request actual (borra fila y cookie). */
    public static function logout(): void {
        $jwt = $_COOKIE[COOKIE_NAME] ?? '';
        if ($jwt !== '') {
            $payload = self::jwtDecode($jwt);
            if ($payload !== null) {
                DB::run('DELETE FROM user_sessions WHERE token_id = ?', [$payload['jti']]);
            }
        }
        self::clearCookie();
    }

    // ---------- Guards ----------

    /** Exige usuario autenticado; corta con error si no lo hay. */
    public static function require(): array {
        $user = self::currentUser();
        if ($user === null) {
            ErrorResponse::send('AUTH_INVALID_TOKEN');
        }
        return $user;
    }

    /** Exige rol admin. */
    public static function requireAdmin(): array {
        $user = self::require();
        if ($user['role'] !== 'admin') {
            ErrorResponse::send('AUTH_INSUFFICIENT_PERMISSIONS');
        }
        return $user;
    }

    /**
     * ¿El usuario tiene la capacidad ('view'|'edit'|'validate') sobre un formulario?
     * Los admin tienen acceso total.
     */
    public static function canForm(array $user, int $formId, string $cap): bool {
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        $col = match ($cap) {
            'view'     => 'can_view',
            'edit'     => 'can_edit',
            'validate' => 'can_validate',
            default    => null,
        };
        if ($col === null) return false;

        $row = DB::run(
            "SELECT $col AS c FROM user_form_permissions WHERE user_id = ? AND form_id = ?",
            [$user['id'], $formId]
        )->fetch();
        return $row && (int) $row['c'] === 1;
    }

    /** Exige una capacidad sobre un formulario; corta con 403 si no la tiene. */
    public static function requireForm(array $user, int $formId, string $cap): void {
        if (!self::canForm($user, $formId, $cap)) {
            ErrorResponse::send('AUTH_INSUFFICIENT_PERMISSIONS');
        }
    }
}
