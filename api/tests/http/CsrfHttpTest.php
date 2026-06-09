<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: protección CSRF en métodos que modifican estado.
 * El front controller bloquea peticiones con un Origin/Referer ajeno; las del propio
 * origen (o sin Origin, p. ej. cron/CLI) pasan.
 */
final class CsrfHttpTest extends HttpTestCase
{
    /** Petición cruda con cabeceras a medida (para fijar/omitir Origin). */
    private function rawPost(string $path, array $headers): array
    {
        $ch = curl_init(self::apiBase() . '/api/v1/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS     => json_encode(['email' => 'x@test.local', 'password' => 'y']),
        ]);
        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['status' => $status, 'json' => json_decode($raw, true)];
    }

    public function testForeignOriginBlockedOnPost(): void
    {
        $res = $this->rawPost('auth/login', ['Origin: https://evil.example.com']);
        $this->assertSame(403, $res['status']);
        $this->assertSame('CSRF_BLOCKED', $res['json']['error']['code']);
    }

    public function testSelfOriginAllowed(): void
    {
        // Mismo origen (lo que manda request()) → no se bloquea por CSRF; llega al endpoint
        // (devuelve 401 por credenciales, no 403).
        $res = $this->request('POST', 'auth/login', ['email' => 'x@test.local', 'password' => 'y']);
        $this->assertSame(401, $res['status']);
        $this->assertSame('VALIDATION_ERROR', $res['json']['error']['code']);
    }

    public function testNoOriginAllowed(): void
    {
        // Sin Origin ni Referer (cliente no-navegador) → no se aplica CSRF (no 403).
        $res = $this->rawPost('auth/login', []);
        $this->assertNotSame(403, $res['status']);
        $this->assertSame(401, $res['status']);
    }
}
