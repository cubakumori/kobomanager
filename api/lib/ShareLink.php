<?php
/**
 * Enlaces de solo lectura compartibles (tabla `share_links`).
 *
 * Un enlace expone una vista de solo lectura de un formulario (lista / detalle /
 * mapa) sin sesión en KoboManager ni cuenta Kobo. El acceso es por un token
 * impredecible en la URL; opcionalmente protegido con contraseña.
 *
 * El subconjunto de envíos visibles se restringe con el mismo motor de scoping
 * por filas que los permisos de viewer (`RowScope`), guardado en `row_filter`.
 *
 * Para enlaces con contraseña se emite un "ticket" firmado (HMAC con JWT_SECRET)
 * de vida corta que el visitante adjunta en las peticiones de datos, evitando
 * reenviar la contraseña en cada llamada.
 */
class ShareLink {

    /** Vida del ticket de acceso para enlaces con contraseña (segundos). */
    private const TICKET_TTL = 3600;

    /** Rate-limit de las peticiones públicas por IP (anti-scraping/DoS). Generoso:
     *  un visitante real carga lista + varios detalles + adjuntos de una galería. */
    private const RATE_MAX    = 240;
    private const RATE_WINDOW = 60;

    /**
     * Throttle por IP de los endpoints públicos de share. Los enlaces se apoyan en
     * un token impredecible + revocación/caducidad; esto añade un tope por IP para
     * frenar el scraping/abuso de un enlace filtrado. Corta con 429 si se excede.
     */
    public static function throttle(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (RateLimit::tooManyBucket($ip, 'share', self::RATE_MAX, self::RATE_WINDOW)) {
            ErrorResponse::send('AUTH_RATE_LIMITED');
        }
        RateLimit::hitBucket($ip, 'share');
    }

    /** Genera un token de enlace impredecible (URL-safe). */
    public static function generateToken(): string {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    /**
     * Resuelve un token a su fila de `share_links` si el enlace está ACTIVO
     * (no revocado y no caducado). Devuelve null en cualquier otro caso.
     */
    public static function resolve(string $token): ?array {
        if ($token === '') return null;
        $row = DB::run(
            'SELECT sl.*, f.name AS form_name, f.schema_json, f.active AS form_active,
                    f.deployment_status, f.last_synced_at
             FROM share_links sl
             JOIN forms f ON f.id = sl.form_id
             WHERE sl.token = ?',
            [$token]
        )->fetch();
        if (!$row) return null;
        if ($row['revoked_at'] !== null) return null;
        if (!(int) $row['form_active']) return null;
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        return $row;
    }

    /** Regla de scoping por filas del enlace (canónica) o null si no restringe. */
    public static function rule(array $link): ?array {
        return RowScope::normalize(
            $link['row_filter'] ? json_decode((string) $link['row_filter'], true) : null
        );
    }

    /** ¿El enlace está protegido con contraseña? */
    public static function hasPassword(array $link): bool {
        return ($link['password_hash'] ?? null) !== null && $link['password_hash'] !== '';
    }

    /** Verifica la contraseña de un enlace protegido. */
    public static function verifyPassword(array $link, string $password): bool {
        if (!self::hasPassword($link)) return true;
        return password_verify($password, (string) $link['password_hash']);
    }

    /** Registra un acceso (contador + última fecha). */
    public static function recordAccess(int $id): void {
        DB::run(
            'UPDATE share_links SET access_count = access_count + 1, last_accessed_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    // ---------- Ticket de acceso (HMAC) para enlaces con contraseña ----------

    private static function b64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $txt): string {
        return base64_decode(strtr($txt, '-_', '+/')) ?: '';
    }

    private static function sign(string $data): string {
        return self::b64url(hash_hmac('sha256', 'share:' . $data, JWT_SECRET, true));
    }

    /** Emite un ticket firmado que acredita haber superado la contraseña del enlace. */
    public static function issueTicket(string $token): string {
        $body = self::b64url(json_encode(['t' => $token, 'exp' => time() + self::TICKET_TTL]));
        return $body . '.' . self::sign($body);
    }

    /** ¿El ticket es válido (firma + no caducado) para este token de enlace? */
    public static function verifyTicket(?string $ticket, string $token): bool {
        if (!$ticket) return false;
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) return false;
        [$body, $sig] = $parts;
        if (!hash_equals(self::sign($body), $sig)) return false;
        $payload = json_decode(self::b64urlDecode($body), true);
        if (!is_array($payload)) return false;
        if (($payload['t'] ?? null) !== $token) return false;
        if (($payload['exp'] ?? 0) < time()) return false;
        return true;
    }

    /**
     * Guard común de los endpoints públicos: resuelve el token, comprueba la
     * capacidad pedida ('list'|'detail'|'map') y, si el enlace tiene contraseña,
     * exige un ticket válido. Corta con el error adecuado o devuelve la fila.
     */
    public static function requireAccess(string $token, string $capability): array {
        self::throttle();
        $link = self::resolve($token);
        if ($link === null) {
            ErrorResponse::send('NOT_FOUND', 'Enlace no válido o caducado');
        }
        $exposeCol = match ($capability) {
            'list'        => 'expose_list',
            'detail'      => 'expose_detail',
            'map'         => 'expose_map',
            'stats'       => 'expose_stats',
            'attachments' => 'expose_attachments',
            default       => null,
        };
        if ($exposeCol === null || !(int) $link[$exposeCol]) {
            ErrorResponse::send('NOT_FOUND', 'Recurso no disponible en este enlace');
        }
        // Los adjuntos respetan además la política global EN VIVO: si el admin la
        // vuelve a 'off', deja de servirlos aunque el enlace tenga la columna a 1
        // (kill-switch, no solo validación en el momento de crear el enlace).
        if ($capability === 'attachments' && Settings::shareAttachmentsPolicy() !== 'require_password') {
            ErrorResponse::send('NOT_FOUND', 'Recurso no disponible en este enlace');
        }
        if (self::hasPassword($link)) {
            $ticket = $_SERVER['HTTP_X_SHARE_TICKET'] ?? ($_GET['k'] ?? null);
            if (!self::verifyTicket($ticket ? (string) $ticket : null, $token)) {
                ErrorResponse::send('SHARE_PASSWORD_REQUIRED');
            }
        }
        return $link;
    }
}
