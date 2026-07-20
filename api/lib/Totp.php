<?php
/**
 * TOTP (RFC 6238) sobre HOTP (RFC 4226), implementado a mano para no depender de
 * librerías externas (mismo criterio que el JWT de Auth y que WebPush): HMAC-SHA1,
 * paso de 30 s, 6 dígitos, ventana de verificación ±1 paso (tolerancia de reloj).
 *
 * El SECRETO es base32 (RFC 4648, alfabeto A-Z2-7, sin relleno), que es lo que las
 * apps autenticadoras esperan en el URI otpauth://. En BD nunca se guarda en claro:
 * viaja cifrado con TokenVault (columna users.totp_secret).
 *
 * ANTI-REPLAY: verify() devuelve el PASO DE TIEMPO que casó (no un booleano); el
 * llamador debe exigir que sea mayor que el último paso aceptado
 * (users.totp_last_step) y persistir el nuevo — un mismo código no vale dos veces.
 *
 * Verificado en TotpTest contra los vectores oficiales de RFC 4226 (Apéndice D)
 * y RFC 6238 (Apéndice B, modo SHA1).
 */
class Totp {

    public const STEP_SECONDS = 30;
    public const DIGITS       = 6;
    /** Pasos de tolerancia a cada lado del actual (±1 = hasta 30 s de deriva). */
    public const WINDOW       = 1;

    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo: 20 bytes aleatorios (160 bits, tamaño canónico SHA1) en base32. */
    public static function generateSecret(): string {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string {
        $out  = '';
        $bits = 0;
        $acc  = 0;
        foreach (str_split($bin) as $ch) {
            $acc  = ($acc << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out  .= self::B32_ALPHABET[($acc >> $bits) & 0x1f];
            }
        }
        if ($bits > 0) {
            $out .= self::B32_ALPHABET[($acc << (5 - $bits)) & 0x1f];
        }
        return $out;
    }

    /** Decodifica base32 (tolera minúsculas, espacios y '='); '' si hay caracteres inválidos. */
    public static function base32Decode(string $b32): string {
        $b32 = strtoupper(str_replace([' ', '='], '', $b32));
        $out  = '';
        $bits = 0;
        $acc  = 0;
        for ($i = 0; $i < strlen($b32); $i++) {
            $v = strpos(self::B32_ALPHABET, $b32[$i]);
            if ($v === false) return '';
            $acc  = ($acc << 5) | $v;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out  .= chr(($acc >> $bits) & 0xff);
            }
        }
        return $out;
    }

    /** HOTP (RFC 4226): HMAC-SHA1 del contador + truncado dinámico. */
    public static function hotp(string $binaryKey, int $counter, int $digits = self::DIGITS): string {
        $msg    = pack('J', $counter); // contador de 8 bytes big-endian
        $hash   = hash_hmac('sha1', $msg, $binaryKey, true);
        $offset = ord($hash[19]) & 0x0f;
        $code   = ((ord($hash[$offset]) & 0x7f) << 24)
                | (ord($hash[$offset + 1]) & 0xff) << 16
                | (ord($hash[$offset + 2]) & 0xff) << 8
                | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($code % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Código TOTP del paso de tiempo dado (paso = floor(unix / 30)). */
    public static function codeAtStep(string $secretB32, int $step, int $digits = self::DIGITS): string {
        return self::hotp(self::base32Decode($secretB32), $step, $digits);
    }

    /**
     * Verifica un código contra la ventana ±WINDOW alrededor de «ahora».
     * Devuelve el PASO que casó (para el anti-replay del llamador) o null.
     * Comparación en tiempo constante; el código se normaliza (espacios fuera).
     */
    public static function verify(string $secretB32, string $code, ?int $now = null, int $window = self::WINDOW): ?int {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) return null;
        $key = self::base32Decode($secretB32);
        if ($key === '') return null;

        $current = intdiv($now ?? time(), self::STEP_SECONDS);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;
            if ($step >= 0 && hash_equals(self::hotp($key, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /**
     * URI otpauth:// para el QR de la app autenticadora.
     * Etiqueta = «{issuer}:{cuenta}» (convención de Google Authenticator).
     */
    public static function otpauthUri(string $secretB32, string $account, string $issuer = 'KoboManager'): string {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secretB32
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::STEP_SECONDS;
    }
}
