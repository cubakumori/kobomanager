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
}
