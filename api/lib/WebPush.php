<?php
/**
 * Web Push nativo — sin dependencias, coherente con el resto del backend (el runtime
 * de KoboManager no usa composer). Implementa lo que un servidor de aplicación
 * necesita para empujar a los push services de los navegadores (FCM, Mozilla, APNs web):
 *
 *   - Cifrado del payload según RFC 8291 (ECDH P-256 + HKDF-SHA256 + AES-128-GCM,
 *     Content-Encoding aes128gcm, un solo registro). Blindado con el vector de
 *     prueba oficial del RFC (§5) en WebPushTest.
 *   - Autenticación VAPID según RFC 8292 (JWT ES256 firmado con la clave privada
 *     del servidor; cabecera `Authorization: vapid t=…, k=…`).
 *
 * Claves VAPID en config.php (generar con `php api/cli/vapid_keys.php`):
 *   VAPID_PUBLIC_KEY   punto P-256 sin comprimir (65 bytes) en base64url — es la
 *                      `applicationServerKey` que usa el navegador al suscribirse.
 *   VAPID_PRIVATE_KEY  escalar privado (32 bytes) en base64url. SECRETO.
 *   VAPID_SUBJECT      contacto del operador (`mailto:` o URL); los push services
 *                      lo usan para avisar de abusos. Si está vacío se usa APP_URL.
 *
 * Solo usa OpenSSL (ext-openssl, ya requerida) y hash_hkdf (PHP ≥ 7.1).
 */
class WebPush {

    /** ¿Hay claves VAPID configuradas? (sin ellas no hay push). */
    public static function configured(): bool {
        return defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY !== ''
            && defined('VAPID_PRIVATE_KEY') && VAPID_PRIVATE_KEY !== '';
    }

    /**
     * Envía un push cifrado a una suscripción. Devuelve el código HTTP del push
     * service (201/200 = aceptado; 404/410 = suscripción muerta → el llamador debe
     * podarla; 0 = error de red).
     *
     * @param array  $sub     ['endpoint' => url, 'p256dh' => b64url, 'auth' => b64url]
     * @param string $payload Texto plano (JSON) a cifrar; máx. ~4 KB — aquí siempre
     *                        recuento + enlace, muy por debajo.
     * @param int    $ttl     Segundos que el push service retiene el mensaje si el
     *                        dispositivo está desconectado.
     */
    public static function send(array $sub, string $payload, int $ttl = 3600): int {
        $endpoint = $sub['endpoint'];
        $body     = self::encrypt(
            $payload,
            self::b64uDecode($sub['p256dh']),
            self::b64uDecode($sub['auth'])
        );

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . self::vapidHeader($endpoint),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($body),
                'TTL: ' . $ttl,
                'Urgency: normal',
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $responseBody = curl_exec($ch);
        $netErr       = curl_errno($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($netErr !== 0) {
            error_log('WebPush: error de red contra ' . parse_url($endpoint, PHP_URL_HOST) . ': ' . curl_error($ch));
            return 0;
        }
        if ($status < 200 || $status >= 300) {
            // 404/410 son esperables (suscripción caducada); el resto se registra.
            if ($status !== 404 && $status !== 410) {
                error_log("WebPush: el push service respondió $status: " . substr((string) $responseBody, 0, 200));
            }
        }
        return $status;
    }

    /** Genera un par de claves VAPID nuevo: ['public' => b64url(65B), 'private' => b64url(32B)]. */
    public static function generateKeys(): array {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('OpenSSL no pudo generar la clave EC: ' . openssl_error_string());
        }
        $d = openssl_pkey_get_details($key);
        return [
            'public'  => self::b64uEncode(self::pointFromDetails($d)),
            'private' => self::b64uEncode(str_pad($d['ec']['d'], 32, "\x00", STR_PAD_LEFT)),
        ];
    }

    // ───────────────────────── RFC 8291: cifrado del payload ─────────────────────────

    /**
     * Cifra `$plaintext` para el navegador (aes128gcm, un solo registro).
     *
     * @param string $uaPublic 65 bytes: punto P-256 sin comprimir de la suscripción (p256dh).
     * @param string $auth     16 bytes: secreto `auth` de la suscripción.
     * @param string|null $asPrivate  Solo tests (vector RFC): escalar privado efímero (32 B).
     * @param string|null $asPublic   Solo tests: punto público efímero (65 B).
     * @param string|null $salt       Solo tests: salt (16 B). En producción, aleatorio.
     * @return string Cuerpo binario completo (cabecera aes128gcm + ciphertext + tag).
     */
    public static function encrypt(
        string $plaintext,
        string $uaPublic,
        string $auth,
        ?string $asPrivate = null,
        ?string $asPublic = null,
        ?string $salt = null
    ): string {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new InvalidArgumentException('p256dh no es un punto P-256 sin comprimir (65 bytes)');
        }
        if (strlen($auth) !== 16) {
            throw new InvalidArgumentException('auth no mide 16 bytes');
        }
        $salt ??= random_bytes(16);

        // Par efímero del servidor (o el inyectado por el test).
        if ($asPrivate === null) {
            $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $det = openssl_pkey_get_details($eph);
            $asPrivate = str_pad($det['ec']['d'], 32, "\x00", STR_PAD_LEFT);
            $asPublic  = self::pointFromDetails($det);
        }

        // ECDH(as_private, ua_public) → secreto compartido (coordenada X, 32 B).
        $ecdh = openssl_pkey_derive(
            openssl_pkey_get_public(self::publicPem($uaPublic)),
            openssl_pkey_get_private(self::privatePem($asPrivate, $asPublic))
        );
        if ($ecdh === false) {
            throw new RuntimeException('ECDH falló: ' . openssl_error_string());
        }

        // Esquema de claves del RFC 8291 (HKDF-SHA256).
        $ikm   = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $uaPublic . $asPublic, $auth);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // Un solo registro: delimitador 0x02 (último registro) y AES-128-GCM.
        $tag = '';
        $ct  = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) {
            throw new RuntimeException('AES-GCM falló: ' . openssl_error_string());
        }

        // Cabecera del cuerpo (RFC 8188): salt (16) + rs (4) + idlen (1) + keyid (as_public).
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
    }

    // ───────────────────────── RFC 8292: cabecera VAPID ─────────────────────────

    /** Valor de `Authorization` para un endpoint dado: `vapid t=<jwt>, k=<clave pública>`. */
    public static function vapidHeader(string $endpoint, ?int $now = null): string {
        $p   = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $sub = (defined('VAPID_SUBJECT') && VAPID_SUBJECT !== '') ? VAPID_SUBJECT : APP_URL;

        $jwt = self::signJwt([
            'aud' => $aud,
            'exp' => ($now ?? time()) + 12 * 3600,   // máx. 24 h; 12 h da margen de reloj
            'sub' => $sub,
        ], self::b64uDecode(VAPID_PRIVATE_KEY), self::b64uDecode(VAPID_PUBLIC_KEY));

        return 'vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY;
    }

    /** JWT ES256 (cabecera.payload.firma, todo base64url). Público para poder testearlo. */
    public static function signJwt(array $claims, string $privateRaw, string $publicRaw): string {
        $signing = self::b64uEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
                 . '.' . self::b64uEncode(json_encode($claims));

        $der = '';
        $ok  = openssl_sign(
            $signing,
            $der,
            openssl_pkey_get_private(self::privatePem($privateRaw, $publicRaw)),
            OPENSSL_ALGO_SHA256
        );
        if (!$ok) {
            throw new RuntimeException('No se pudo firmar el JWT VAPID: ' . openssl_error_string());
        }
        return $signing . '.' . self::b64uEncode(self::derToRawSignature($der));
    }

    /** Firma ECDSA DER → cruda r‖s (64 bytes), el formato que exige JWS/ES256. */
    private static function derToRawSignature(string $der): string {
        // SEQUENCE { INTEGER r, INTEGER s } — se leen los dos INTEGER a mano.
        $off = 2;                                   // 0x30 <len>
        if ((ord($der[1]) & 0x80) !== 0) {
            $off += ord($der[1]) & 0x7f;            // longitud multi-byte
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len  = ord($der[$off + 1]);
            $int  = substr($der, $off + 2, $len);
            $int  = ltrim($int, "\x00");            // quita el 0x00 de signo
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
            $off += 2 + $len;
        }
        return $out;
    }

    // ───────────────────────── Claves P-256 crudas ↔ PEM ─────────────────────────

    /**
     * PEM de clave pública (SPKI) a partir del punto sin comprimir (65 B). La
     * estructura DER de P-256 es fija, así que basta anteponer la plantilla.
     */
    public static function publicPem(string $point65): string {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point65;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** PEM de clave privada (SEC1) a partir del escalar (32 B) y su punto público (65 B). */
    public static function privatePem(string $d32, string $point65): string {
        $der = hex2bin('30770201010420') . $d32
             . hex2bin('a00a06082a8648ce3d030107a144034200') . $point65;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /** Punto sin comprimir (65 B) desde los detalles de openssl (x/y pueden venir cortos). */
    private static function pointFromDetails(array $details): string {
        return "\x04"
            . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    // ───────────────────────── base64url ─────────────────────────

    public static function b64uEncode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $b64u): string {
        $b64 = strtr($b64u, '-_', '+/');
        $out = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
        if ($out === false) {
            throw new InvalidArgumentException('base64url inválido');
        }
        return $out;
    }
}
