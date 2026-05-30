<?php
/**
 * Cifrado autenticado de los tokens de la API de Kobo con libSodium
 * (sodium_crypto_secretbox). La clave maestra vive en config.php (CONFIG_TOKEN_KEY),
 * nunca en la base de datos.
 *
 * Generar la clave una vez:
 *   php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
 */
class TokenVault {
    public static function encrypt(string $plaintext): string {
        $key    = sodium_hex2bin(CONFIG_TOKEN_KEY);
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return sodium_bin2hex($nonce . $cipher);
    }

    public static function decrypt(string $encoded): string {
        $key    = sodium_hex2bin(CONFIG_TOKEN_KEY);
        $raw    = sodium_hex2bin($encoded);
        $nonce  = mb_substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher = mb_substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new RuntimeException('TokenVault: descifrado fallido (clave incorrecta o dato manipulado)');
        }
        return $plain;
    }
}
