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

    public function testForwardedIpIsHonoredFromTrustedProxy(): void
    {
        // Cinco fallos desde la IP reenviada A agotan SU cuota…
        $this->seedUser('viewer', 'fwd@test.local', 'Secret123!');
        for ($i = 0; $i < 5; $i++) {
            $this->request('POST', 'auth/login', ['email' => 'fwd@test.local', 'password' => 'bad'], null, ['X-Forwarded-For: 198.51.100.1']);
        }
        $res = $this->request('POST', 'auth/login', ['email' => 'fwd@test.local', 'password' => 'Secret123!'], null, ['X-Forwarded-For: 198.51.100.1']);
        $this->assertSame(429, $res['status']);
        // …pero otro cliente detrás del mismo proxy sigue pudiendo entrar.
        $res = $this->request('POST', 'auth/login', ['email' => 'fwd@test.local', 'password' => 'Secret123!'], null, ['X-Forwarded-For: 198.51.100.2']);
        $this->assertSame(200, $res['status'], $res['raw']);
        // Y la sesión registra la IP reenviada, no la del proxy.
        $ip = DB::run('SELECT ip FROM user_sessions ORDER BY id DESC LIMIT 1')->fetch()['ip'];
        $this->assertSame('198.51.100.2', $ip);
    }

    public function testPerAccountLimitStopsIpRotation(): void
    {
        $this->seedUser('viewer', 'acct@test.local', 'Secret123!');
        // 20 fallos contra la MISMA cuenta desde 20 IPs distintas (la capa por IP
        // nunca salta: cada IP falla una sola vez).
        for ($i = 1; $i <= 20; $i++) {
            $res = $this->request('POST', 'auth/login', ['email' => 'acct@test.local', 'password' => 'bad'], null, ["X-Forwarded-For: 198.51.100.$i"]);
            $this->assertSame(401, $res['status'], "intento $i");
        }
        // El 21.º, con la contraseña CORRECTA y una IP nueva, cae por la capa por cuenta.
        $res = $this->request('POST', 'auth/login', ['email' => 'acct@test.local', 'password' => 'Secret123!'], null, ['X-Forwarded-For: 198.51.100.99']);
        $this->assertSame(429, $res['status']);
        $this->assertSame('AUTH_RATE_LIMITED', $res['json']['error']['code']);
        // Otra cuenta no se ve afectada.
        $this->seedUser('viewer', 'other@test.local', 'Secret123!');
        $res = $this->request('POST', 'auth/login', ['email' => 'other@test.local', 'password' => 'Secret123!'], null, ['X-Forwarded-For: 198.51.100.99']);
        $this->assertSame(200, $res['status'], $res['raw']);
    }

    public function testPerAccountLimitDoesNotRevealWhetherAccountExists(): void
    {
        // Mismo 429 para un email inexistente tras 20 fallos: no hay oráculo.
        for ($i = 1; $i <= 20; $i++) {
            $this->request('POST', 'auth/login', ['email' => 'ghost@test.local', 'password' => 'bad'], null, ["X-Forwarded-For: 198.51.100.$i"]);
        }
        $res = $this->request('POST', 'auth/login', ['email' => 'ghost@test.local', 'password' => 'bad'], null, ['X-Forwarded-For: 198.51.100.99']);
        $this->assertSame(429, $res['status']);
    }

    public function testChangingOwnPasswordClosesOtherSessions(): void
    {
        $this->seedUser('viewer', 'two@test.local', 'Secret123!');
        $jarA = $this->login('two@test.local', 'Secret123!');
        $jarB = $this->login('two@test.local', 'Secret123!');
        $this->assertSame(200, $this->request('GET', 'auth/me', null, $jarB)['status']);

        $res = $this->request('POST', 'profile/password', [
            'current_password' => 'Secret123!', 'new_password' => 'Fresh-Start-77',
        ], $jarA);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(1, $res['json']['data']['sessions_closed']);
        // La sesión que hizo el cambio sigue; la otra queda revocada.
        $this->assertSame(200, $this->request('GET', 'auth/me', null, $jarA)['status']);
        $this->assertSame(401, $this->request('GET', 'auth/me', null, $jarB)['status']);
        @unlink($jarA); @unlink($jarB);
    }

    public function testAdminPasswordChangeClosesUserSessionsAndEnforcesPolicy(): void
    {
        $this->seedUser('admin', 'boss@test.local', 'Secret123!');
        $uid = $this->seedUser('viewer', 'staff@test.local', 'Secret123!');
        $jarAdmin = $this->login('boss@test.local', 'Secret123!');
        $jarStaff = $this->login('staff@test.local', 'Secret123!');

        // Contraseña común → 422 PASSWORD_WEAK y nada cambia.
        $res = $this->request('PUT', "admin/users/$uid", [
            'name' => 'Staff', 'email' => 'staff@test.local', 'role' => 'viewer', 'password' => 'Password123!',
        ], $jarAdmin);
        $this->assertSame(422, $res['status']);
        $this->assertSame('PASSWORD_WEAK', $res['json']['error']['code']);
        $this->assertSame(200, $this->request('GET', 'auth/me', null, $jarStaff)['status']);

        // Contraseña válida → se aplica y las sesiones del usuario se cierran.
        $res = $this->request('PUT', "admin/users/$uid", [
            'name' => 'Staff', 'email' => 'staff@test.local', 'role' => 'viewer', 'password' => 'Fresh-Start-77',
        ], $jarAdmin);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(401, $this->request('GET', 'auth/me', null, $jarStaff)['status']);
        // La del admin no se toca.
        $this->assertSame(200, $this->request('GET', 'auth/me', null, $jarAdmin)['status']);
        @unlink($jarAdmin); @unlink($jarStaff);
    }

    public function testConfigWithoutDemoConstantsReportsDemoOff(): void
    {
        // Retrocompatibilidad: la config de test base NO define DEMO_MODE (como
        // cualquier config.php anterior) → la API reporta demo off y las acciones
        // de la denylist (p. ej. cambiar la contraseña propia) siguen abiertas.
        $res = $this->request('GET', 'config');
        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['json']['data']['demo_mode']);

        $this->seedUser('viewer', 'pw@test.local', 'Secret123!');
        $jar = $this->login('pw@test.local', 'Secret123!');
        $res = $this->request('POST', 'profile/password', [
            'current_password' => 'Secret123!',
            'new_password'     => 'Another123!',
        ], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        @unlink($jar);
    }
}
