<?php

declare(strict_types=1);

/**
 * Tests de lib/Totp contra los VECTORES OFICIALES: HOTP (RFC 4226, Apéndice D)
 * y TOTP (RFC 6238, Apéndice B, modo SHA1 con 8 dígitos), más base32, la ventana
 * de verificación con el paso devuelto (anti-replay) y el URI otpauth://.
 */
final class TotpTest extends DbTestCase
{
    /** Secreto ASCII de los vectores de ambos RFC. */
    private const RFC_KEY = '12345678901234567890';

    public function testHotpRfc4226Vectors(): void
    {
        // RFC 4226, Apéndice D: contadores 0..9 con la clave ASCII estándar.
        $expected = [
            '755224', '287082', '359152', '969429', '338314',
            '254676', '287922', '162583', '399871', '520489',
        ];
        foreach ($expected as $counter => $code) {
            $this->assertSame($code, Totp::hotp(self::RFC_KEY, $counter), "counter $counter");
        }
    }

    public function testTotpRfc6238Sha1Vectors(): void
    {
        // RFC 6238, Apéndice B (SHA1, T0=0, paso 30 s, 8 dígitos).
        $secret = Totp::base32Encode(self::RFC_KEY);
        $vectors = [
            59          => '94287082',
            1111111109  => '07081804',
            1111111111  => '14050471',
            1234567890  => '89005924',
            2000000000  => '69279037',
            20000000000 => '65353130',
        ];
        foreach ($vectors as $time => $code) {
            $step = intdiv($time, Totp::STEP_SECONDS);
            $this->assertSame($code, Totp::codeAtStep($secret, $step, 8), "T=$time");
        }
    }

    public function testBase32RoundTrip(): void
    {
        // Ida y vuelta de binario arbitrario, y decodificación tolerante
        // (minúsculas/espacios/relleno) del mismo secreto.
        $bin = random_bytes(20);
        $b32 = Totp::base32Encode($bin);
        $this->assertSame($bin, Totp::base32Decode($b32));
        $this->assertSame($bin, Totp::base32Decode(strtolower($b32) . '=='));
        // Caracteres fuera del alfabeto → cadena vacía (secreto inválido).
        $this->assertSame('', Totp::base32Decode('ABC!DEF'));
    }

    public function testVerifyWindowAndMatchedStep(): void
    {
        $secret = Totp::generateSecret();
        $now    = 1700000000;
        $step   = intdiv($now, Totp::STEP_SECONDS);

        // El código del paso actual y los de ±1 casan; el de ±2 no.
        $this->assertSame($step, Totp::verify($secret, Totp::codeAtStep($secret, $step), $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::codeAtStep($secret, $step - 1), $now));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::codeAtStep($secret, $step + 1), $now));
        $this->assertNull(Totp::verify($secret, Totp::codeAtStep($secret, $step - 2), $now));

        // Normaliza espacios («123 456») y rechaza formatos no numéricos.
        $code = Totp::codeAtStep($secret, $step);
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);
        $this->assertSame($step, Totp::verify($secret, $spaced, $now));
        $this->assertNull(Totp::verify($secret, 'abcdef', $now));
        $this->assertNull(Totp::verify($secret, '12345', $now));
    }

    public function testGenerateSecretShapeAndUri(): void
    {
        $secret = Totp::generateSecret();
        // 20 bytes → 32 caracteres base32, solo alfabeto RFC 4648.
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);

        $uri = Totp::otpauthUri($secret, 'ana@ong.org', 'KoboManager');
        $this->assertStringStartsWith('otpauth://totp/KoboManager%3Aana%40ong.org?', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=KoboManager', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
