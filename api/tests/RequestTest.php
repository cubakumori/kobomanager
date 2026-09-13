<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * IP real del cliente y esquema tras un proxy inverso (lib/Request): solo se
 * honran X-Forwarded-For / X-Forwarded-Proto cuando REMOTE_ADDR es un proxy de
 * confianza; la cadena se recorre desde el servidor hacia el origen. Clase pura.
 */
final class RequestTest extends TestCase
{
    public function testWithoutTrustedProxiesRemoteAddrWins(): void
    {
        $srv = ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        $this->assertSame('203.0.113.10', Request::clientIp([], $srv));
    }

    public function testUntrustedRemoteCannotSpoofViaHeader(): void
    {
        $srv = ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        $this->assertSame('203.0.113.10', Request::clientIp(['10.0.0.1'], $srv));
    }

    public function testTrustedProxyYieldsForwardedClient(): void
    {
        $srv = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        $this->assertSame('198.51.100.7', Request::clientIp(['10.0.0.1'], $srv));
    }

    public function testChainSkipsKnownProxiesFromTheRightAndIgnoresClientInjectedPrefix(): void
    {
        // El cliente puso «1.2.3.4» al principio; dos proxies de confianza añadieron su salto.
        $srv = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7, 10.0.0.1'];
        $this->assertSame('198.51.100.7', Request::clientIp(['10.0.0.0/8'], $srv));
    }

    public function testMalformedChainFallsBackToRemote(): void
    {
        // «unknown» como salto más cercano al servidor: no se confía en la cadena.
        $srv = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, unknown'];
        $this->assertSame('10.0.0.1', Request::clientIp(['10.0.0.1'], $srv));
        // Basura MÁS ALLÁ del cliente real (inyectada por él) no cambia nada.
        $srv = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'unknown, 198.51.100.7'];
        $this->assertSame('198.51.100.7', Request::clientIp(['10.0.0.1'], $srv));
    }

    public function testIpv6AndPortsAreNormalized(): void
    {
        $srv = ['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_FOR' => '[2001:db8::42]:443'];
        $this->assertSame('2001:db8::42', Request::clientIp(['::1'], $srv));
        $srv = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7:5555'];
        $this->assertSame('198.51.100.7', Request::clientIp(['127.0.0.1'], $srv));
    }

    public function testCidrMatchingV4AndV6(): void
    {
        $this->assertTrue(Request::ipInList('10.20.30.40', ['10.0.0.0/8']));
        $this->assertFalse(Request::ipInList('11.0.0.1', ['10.0.0.0/8']));
        $this->assertTrue(Request::ipInList('192.168.1.130', ['192.168.1.128/25']));
        $this->assertFalse(Request::ipInList('192.168.1.127', ['192.168.1.128/25']));
        $this->assertTrue(Request::ipInList('2400:cb00:1::5', ['2400:cb00::/32']));
        $this->assertFalse(Request::ipInList('2400:cb01::5', ['2400:cb00::/32']));
        // Familias distintas no se mezclan; entradas basura se ignoran.
        $this->assertFalse(Request::ipInList('10.0.0.1', ['2400:cb00::/32', 'garbage', '10.0.0.0/99']));
    }

    public function testIsHttpsDirectAndForwarded(): void
    {
        $this->assertTrue(Request::isHttps([], ['HTTPS' => 'on']));
        $this->assertTrue(Request::isHttps([], ['SERVER_PORT' => 443]));
        $this->assertFalse(Request::isHttps([], ['HTTPS' => 'off', 'SERVER_PORT' => 80]));
        // X-Forwarded-Proto solo cuenta desde un proxy de confianza.
        $fwd = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertFalse(Request::isHttps([], $fwd));
        $this->assertTrue(Request::isHttps(['10.0.0.1'], $fwd));
        $this->assertFalse(Request::isHttps(['10.0.0.1'], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http']));
    }
}
