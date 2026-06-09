<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: ciclo de autenticación y sesión (login / me / logout / JWT / rate-limit).
 */
final class AuthHttpTest extends HttpTestCase
{
    public function testLoginSuccessSetsSessionAndMeWorks(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');

        $jar = $this->login('admin@test.local', 'Secret123!');

        // La cookie de sesión quedó en el jar.
        $this->assertStringContainsString(COOKIE_NAME, file_get_contents($jar));

        // /auth/me con la sesión devuelve el usuario.
        $me = $this->request('GET', 'auth/me', null, $jar);
        $this->assertSame(200, $me['status']);
        $this->assertSame('admin@test.local', $me['json']['data']['email']);
        $this->assertSame('admin', $me['json']['data']['role']);
        @unlink($jar);
    }

    public function testLoginWrongPasswordIs401Generic(): void
    {
        $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $res = $this->request('POST', 'auth/login', ['email' => 'v@test.local', 'password' => 'nope']);
        $this->assertSame(401, $res['status']);
        $this->assertFalse($res['json']['success']);
    }

    public function testInactiveUserCannotLogin(): void
    {
        $this->seedUser('viewer', 'off@test.local', 'Secret123!', active: false);
        $res = $this->request('POST', 'auth/login', ['email' => 'off@test.local', 'password' => 'Secret123!']);
        $this->assertSame(401, $res['status']);
    }

    public function testMeWithoutSessionIs401(): void
    {
        $res = $this->request('GET', 'auth/me');
        $this->assertSame(401, $res['status']);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $this->seedUser('admin', 'a@test.local', 'Secret123!');
        $jar = $this->login('a@test.local', 'Secret123!');

        $this->assertSame(200, $this->request('GET', 'auth/me', null, $jar)['status']);
        $this->assertSame(200, $this->request('POST', 'auth/logout', [], $jar)['status']);
        // Tras logout la sesión (jti) queda revocada → /auth/me ya no autentica.
        $this->assertSame(401, $this->request('GET', 'auth/me', null, $jar)['status']);
        @unlink($jar);
    }

    public function testLoginRateLimitedAfterFiveFailures(): void
    {
        $this->seedUser('viewer', 'rl@test.local', 'Secret123!');
        for ($i = 0; $i < 5; $i++) {
            $this->request('POST', 'auth/login', ['email' => 'rl@test.local', 'password' => 'bad']);
        }
        // El 6.º intento (mismo IP) es rechazado por rate-limit, no por credenciales.
        $res = $this->request('POST', 'auth/login', ['email' => 'rl@test.local', 'password' => 'Secret123!']);
        $this->assertSame(429, $res['status']);
        $this->assertSame('AUTH_RATE_LIMITED', $res['json']['error']['code']);
    }
}
