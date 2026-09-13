<?php
/**
 * Utilidades para leer la entrada de la petición.
 */
class Request {
    private static ?array $jsonCache = null;

    /** Parámetros extraídos de la ruta dinámica (ej. {id}). Los fija el front controller. */
    public static array $params = [];

    public static function param(string $key, mixed $default = null): mixed {
        return self::$params[$key] ?? $default;
    }

    /** Cuerpo JSON de la petición como array asociativo. */
    /** Tope del cuerpo JSON (anti-DoS por memoria). Generoso para edición de envíos. */
    private const MAX_BODY_BYTES = 2 * 1024 * 1024; // 2 MB

    public static function json(): array {
        if (self::$jsonCache === null) {
            // Rechaza por Content-Length antes de leer; además acota la lectura para
            // no materializar en memoria un cuerpo enorme aunque la cabecera mienta.
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
                ErrorResponse::send('VALIDATION_ERROR', 'Cuerpo de la petición demasiado grande', 413);
            }
            $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1) ?: '';
            if (strlen($raw) > self::MAX_BODY_BYTES) {
                ErrorResponse::send('VALIDATION_ERROR', 'Cuerpo de la petición demasiado grande', 413);
            }
            $data = json_decode($raw, true);
            self::$jsonCache = is_array($data) ? $data : [];
        }
        return self::$jsonCache;
    }

    /** Exige que los campos indicados existan y no estén vacíos; corta con VALIDATION_ERROR si no. */
    public static function required(array $fields): array {
        $body = self::json();
        $out = [];
        foreach ($fields as $f) {
            $v = $body[$f] ?? null;
            if ($v === null || (is_string($v) && trim($v) === '')) {
                ErrorResponse::send('VALIDATION_ERROR', "Falta el campo obligatorio: $f");
            }
            $out[$f] = is_string($v) ? trim($v) : $v;
        }
        return $out;
    }

    public static function method(): string {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    // ---------- Proxy inverso: IP real del cliente y esquema ----------

    /**
     * Lista de proxies de confianza (`TRUSTED_PROXIES` en config.php: IPs o CIDR,
     * IPv4/IPv6). Opcional: sin la constante, ninguna cabecera `X-Forwarded-*` se
     * cree (comportamiento clásico: REMOTE_ADDR es el cliente).
     */
    public static function trustedProxies(): array {
        if (!defined('TRUSTED_PROXIES') || !is_array(TRUSTED_PROXIES)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', TRUSTED_PROXIES), fn($v) => $v !== ''));
    }

    /**
     * IP del cliente que originó la petición.
     *
     * Detrás de un proxy inverso (Cloudflare, nginx terminando TLS, el proxy del
     * hosting) `REMOTE_ADDR` es la IP del proxy, y TODOS los visitantes la
     * comparten: los límites por IP (login, desbloqueo de enlaces, throttle
     * público, contacto) se aplicarían al proxy entero — cinco fallos de login
     * de cualquiera bloquean a toda la organización — y auditoría/sesiones
     * registran la IP equivocada.
     *
     * Solo se cree `X-Forwarded-For` cuando `REMOTE_ADDR` es un proxy de
     * confianza; la cadena se recorre de derecha a izquierda saltando proxies
     * conocidos y la primera IP NO confiable es el cliente (así un cliente no
     * puede inyectar una IP falsa al principio de la cabecera). Cualquier valor
     * inválido cae a `REMOTE_ADDR`.
     *
     * @param array|null $trusted Sobrescribe la lista de la config (tests).
     * @param array|null $server  Sobrescribe $_SERVER (tests).
     */
    public static function clientIp(?array $trusted = null, ?array $server = null): string {
        $server  ??= $_SERVER;
        $trusted ??= self::trustedProxies();
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remote === '' || !$trusted || !self::ipInList($remote, $trusted)) {
            return $remote !== '' ? $remote : '0.0.0.0';
        }
        $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if (trim($xff) === '') {
            return $remote;
        }
        $chain = array_values(array_filter(array_map('trim', explode(',', $xff)), fn($v) => $v !== ''));
        // Recorrer desde el salto más cercano al servidor hacia el origen.
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $hop = self::normalizeIp($chain[$i]);
            if ($hop === null) {
                return $remote; // cadena malformada: no confiar en nada de ella
            }
            if (!self::ipInList($hop, $trusted)) {
                return $hop;
            }
        }
        // Toda la cadena son proxies conocidos: el más lejano es lo mejor que hay.
        return self::normalizeIp($chain[0]) ?? $remote;
    }

    /**
     * ¿La petición llegó por HTTPS? Directo (`HTTPS`/puerto 443) o, tras un proxy de
     * confianza que termina TLS, por `X-Forwarded-Proto: https`. Gobierna HSTS, el
     * esquema del origen propio del CSRF y el aviso de config de /health.
     */
    public static function isHttps(?array $trusted = null, ?array $server = null): bool {
        $server ??= $_SERVER;
        if ((!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (int) ($server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $trusted ??= self::trustedProxies();
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($trusted && $remote !== '' && self::ipInList($remote, $trusted)) {
            $proto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            return $proto === 'https';
        }
        return false;
    }

    /** ¿Hay cabecera X-Forwarded-For que NO se está honrando (proxy no declarado)? Para /health. */
    public static function forwardedButUntrusted(?array $server = null): bool {
        $server ??= $_SERVER;
        if (trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? '')) === '') {
            return false;
        }
        $remote  = (string) ($server['REMOTE_ADDR'] ?? '');
        $trusted = self::trustedProxies();
        return !$trusted || $remote === '' || !self::ipInList($remote, $trusted);
    }

    /** IP saneada (sin puerto ni corchetes IPv6) o null si no es una IP válida. */
    private static function normalizeIp(string $raw): ?string {
        $v = trim($raw);
        // "[2001:db8::1]:443" → "2001:db8::1"; "1.2.3.4:5678" → "1.2.3.4"
        if (preg_match('/^\[([0-9a-fA-F:]+)\](?::\d+)?$/', $v, $m)) {
            $v = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $v, $m)) {
            $v = $m[1];
        }
        return filter_var($v, FILTER_VALIDATE_IP) !== false ? $v : null;
    }

    /** ¿La IP está en la lista (IPs sueltas o rangos CIDR, IPv4/IPv6)? */
    public static function ipInList(string $ip, array $list): bool {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        foreach ($list as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') continue;
            if (!str_contains($entry, '/')) {
                $eb = @inet_pton($entry);
                if ($eb !== false && $eb === $bin) return true;
                continue;
            }
            [$net, $bits] = explode('/', $entry, 2);
            $nb = @inet_pton(trim($net));
            if ($nb === false || strlen($nb) !== strlen($bin) || !ctype_digit($bits)) continue;
            $bits = (int) $bits;
            if ($bits < 0 || $bits > strlen($bin) * 8) continue;
            if (self::prefixMatch($bin, $nb, $bits)) return true;
        }
        return false;
    }

    /** ¿Los primeros $bits bits de dos direcciones binarias coinciden? */
    private static function prefixMatch(string $a, string $b, int $bits): bool {
        $full = intdiv($bits, 8);
        if ($full > 0 && substr($a, 0, $full) !== substr($b, 0, $full)) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }
}
