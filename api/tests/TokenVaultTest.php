<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Cifrado de tokens Kobo (libSodium). */
final class TokenVaultTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $plain = 'token_secreto_de_kobo_123';
        $enc = TokenVault::encrypt($plain);
        $this->assertNotSame($plain, $enc);
        $this->assertSame($plain, TokenVault::decrypt($enc));
    }

    public function testNonceMakesCiphertextNonDeterministic(): void
    {
        $this->assertNotSame(TokenVault::encrypt('x'), TokenVault::encrypt('x'));
    }

    public function testTamperedCiphertextThrows(): void
    {
        $enc = TokenVault::encrypt('hola');
        $tampered = substr($enc, 0, -2) . (str_ends_with($enc, '00') ? '11' : '00');
        $this->expectException(RuntimeException::class);
        TokenVault::decrypt($tampered);
    }

    // ---------- Rotación de clave (función pura) ----------

    private function freshKey(): string
    {
        return sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testReencryptRotatesKey(): void
    {
        $old = $this->freshKey();
        $new = $this->freshKey();
        $plain = 'token_secreto_de_kobo_123';

        $enc = TokenVault::encrypt($plain, $old);
        $rot = TokenVault::reencrypt($enc, $old, $new);

        // Con la clave nueva descifra; con la vieja ya no.
        $this->assertSame($plain, TokenVault::decrypt($rot, $new));
        $this->expectException(RuntimeException::class);
        TokenVault::decrypt($rot, $old);
    }

    public function testDecryptWithWrongKeyThrows(): void
    {
        $enc = TokenVault::encrypt('hola', $this->freshKey());
        $this->expectException(RuntimeException::class);
        TokenVault::decrypt($enc, $this->freshKey());
    }

    public function testInvalidKeyLengthThrows(): void
    {
        $this->expectException(RuntimeException::class);
        TokenVault::encrypt('x', 'abcd'); // 2 bytes, no 32
    }
}
