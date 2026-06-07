<?php
/**
 * Cifrado autenticado de los tokens de la API de Kobo con libSodium
 * (sodium_crypto_secretbox). La clave maestra vive en config.php (CONFIG_TOKEN_KEY),
 * nunca en la base de datos.
 *
 * Generar la clave una vez:
 *   php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
 *
 * Las funciones aceptan una clave explícita (hex) para poder ROTAR la clave maestra
 * re-cifrando de la vieja a la nueva (ver cli/rotate_token_key.php). Sin argumento de
 * clave usan CONFIG_TOKEN_KEY.
 */
class TokenVault {
    /** Convierte una clave hex a binario y valida su longitud. */
    private static function keyBin(?string $keyHex): string {
        $hex = $keyHex ?? CONFIG_TOKEN_KEY;
        $key = sodium_hex2bin($hex);
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('TokenVault: clave inválida (se esperan '
                . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes en hex)');
        }
        return $key;
    }

    public static function encrypt(string $plaintext, ?string $keyHex = null): string {
        $key    = self::keyBin($keyHex);
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return sodium_bin2hex($nonce . $cipher);
    }

    public static function decrypt(string $encoded, ?string $keyHex = null): string {
        $key    = self::keyBin($keyHex);
        $raw    = sodium_hex2bin($encoded);
        $nonce  = mb_substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher = mb_substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new RuntimeException('TokenVault: descifrado fallido (clave incorrecta o dato manipulado)');
        }
        return $plain;
    }

    /** Re-cifra un valor de la clave vieja a la nueva (función pura, testeable). */
    public static function reencrypt(string $encoded, string $oldKeyHex, string $newKeyHex): string {
        return self::encrypt(self::decrypt($encoded, $oldKeyHex), $newKeyHex);
    }
}
