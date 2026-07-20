<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del segundo factor (TOTP): enrolamiento completo (init →
 * confirm → códigos de recuperación), login en dos pasos con anti-replay,
 * códigos de recuperación de un solo uso, desactivación con código, la política
 * `require_2fa` (corte TOTP_ENROLL_REQUIRED + guardarraíl del propio admin) y
 * el reset por admin.
 *
 * Los códigos TOTP se calculan con la MISMA lib (Totp) sobre el secreto que
 * devuelve el init: el arnés comparte reloj con el servidor efímero.
 */
final class TotpHttpTest extends HttpTestCase
{
    /** Paso de tiempo actual (el mismo que usará el servidor, misma máquina). */
    private function nowStep(): int
    {
        return intdiv(time(), Totp::STEP_SECONDS);
    }

    /** Enrola 2FA para la sesión dada; devuelve [secret, recovery_codes]. */
    private function enroll(string $jar): array
    {
        $res = $this->request('POST', 'profile/totp/init', [], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $secret = $res['json']['data']['secret'];
        $this->assertStringStartsWith('otpauth://totp/', $res['json']['data']['otpauth_uri']);

        $res = $this->request('POST', 'profile/totp/confirm', ['code' => Totp::codeAtStep($secret, $this->nowStep())], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $codes = $res['json']['data']['recovery_codes'];
        $this->assertCount(8, $codes);
        return [$secret, $codes];
    }

    public function testEnrollmentAndTwoStepLogin(): void
    {
        $this->seedUser('viewer', 'totp@test.local', 'Secret123!');
        $jar = $this->login('totp@test.local', 'Secret123!');

        // Estado inicial: sin 2FA.
        $res = $this->request('GET', 'profile/totp', null, $jar);
        $this->assertFalse($res['json']['data']['enabled']);

        [$secret] = $this->enroll($jar);

        // En BD el secreto queda CIFRADO (nunca el base32 en claro).
        $row = DB::run('SELECT totp_secret, totp_enabled_at FROM users WHERE email = ?', ['totp@test.local'])->fetch();
        $this->assertNotNull($row['totp_enabled_at']);
        $this->assertStringNotContainsString($secret, (string) $row['totp_secret']);

        // Login nuevo: las credenciales solas devuelven el reto, SIN cookie de sesión.
        $jar2 = tempnam(sys_get_temp_dir(), 'kmjar');
        $res = $this->request('POST', 'auth/login', ['email' => 'totp@test.local', 'password' => 'Secret123!'], $jar2);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertTrue($res['json']['data']['totp_required']);
        $challenge = $res['json']['data']['challenge'];
        $me = $this->request('GET', 'auth/me', null, $jar2);
        $this->assertSame(401, $me['status'], 'el reto no debe abrir sesión');

        // Código inválido → 401 TOTP_INVALID.
        $res = $this->request('POST', 'auth/login/totp', ['challenge' => $challenge, 'code' => '000000'], $jar2);
        $this->assertSame(401, $res['status']);
        $this->assertSame('TOTP_INVALID', $res['json']['error']['code']);

        // Código válido (paso siguiente al consumido por el confirm) → sesión real.
        $code = Totp::codeAtStep($secret, $this->nowStep() + 1);
        $res = $this->request('POST', 'auth/login/totp', ['challenge' => $challenge, 'code' => $code], $jar2);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertTrue($res['json']['data']['totp_enabled']);
        $me = $this->request('GET', 'auth/me', null, $jar2);
        $this->assertSame(200, $me['status']);
        $this->assertTrue($me['json']['data']['totp_enabled']);

        // ANTI-REPLAY: el mismo código no vale una segunda vez.
        $res = $this->request('POST', 'auth/login', ['email' => 'totp@test.local', 'password' => 'Secret123!'], $jar2);
        $challenge2 = $res['json']['data']['challenge'];
        $res = $this->request('POST', 'auth/login/totp', ['challenge' => $challenge2, 'code' => $code], $jar2);
        $this->assertSame(401, $res['status'], 'replay del mismo código debería fallar');
        $this->assertSame('TOTP_INVALID', $res['json']['error']['code']);

        // Reto corrupto → TOTP_CHALLENGE_EXPIRED.
        $res = $this->request('POST', 'auth/login/totp', ['challenge' => 'no.es.unreto', 'code' => $code], $jar2);
        $this->assertSame(401, $res['status']);
        $this->assertSame('TOTP_CHALLENGE_EXPIRED', $res['json']['error']['code']);

        @unlink($jar);
        @unlink($jar2);
    }

    public function testRecoveryCodesAreSingleUse(): void
    {
        $this->seedUser('viewer', 'rec@test.local', 'Secret123!');
        $jar = $this->login('rec@test.local', 'Secret123!');
        [, $codes] = $this->enroll($jar);

        $challenge = fn() => $this->request(
            'POST', 'auth/login', ['email' => 'rec@test.local', 'password' => 'Secret123!']
        )['json']['data']['challenge'];

        // Un código de recuperación abre sesión…
        $res = $this->request('POST', 'auth/login/totp', ['challenge' => $challenge(), 'code' => $codes[0]]);
        $this->assertSame(200, $res['status'], $res['raw']);

        // …queda descontado y NO vale una segunda vez.
        $left = $this->request('GET', 'profile/totp', null, $jar)['json']['data']['recovery_left'];
        $this->assertSame(7, $left);
        $res = $this->request('POST', 'auth/login/totp', ['challenge' => $challenge(), 'code' => $codes[0]]);
        $this->assertSame(401, $res['status']);
        $this->assertSame('TOTP_INVALID', $res['json']['error']['code']);

        @unlink($jar);
    }

    public function testDisableRequiresCodeAndPolicyBlocksIt(): void
    {
        $this->seedUser('viewer', 'dis@test.local', 'Secret123!');
        $jar = $this->login('dis@test.local', 'Secret123!');
        [$secret] = $this->enroll($jar);

        // Sin código válido no se desactiva.
        $res = $this->request('DELETE', 'profile/totp', ['code' => '000000'], $jar);
        $this->assertSame(401, $res['status']);

        // Con la política 'all' activa, ni siquiera con código.
        DB::run("INSERT INTO settings (`key`, `value`) VALUES ('require_2fa', '\"all\"')
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $res = $this->request('DELETE', 'profile/totp', ['code' => Totp::codeAtStep($secret, $this->nowStep() + 1)], $jar);
        $this->assertSame(403, $res['status'], $res['raw']);

        // Política fuera → un código válido (aún no usado) lo desactiva.
        DB::run("UPDATE settings SET `value` = '\"off\"' WHERE `key` = 'require_2fa'");
        $res = $this->request('DELETE', 'profile/totp', ['code' => Totp::codeAtStep($secret, $this->nowStep() + 1)], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $row = DB::run('SELECT totp_secret, totp_enabled_at, totp_recovery_codes FROM users WHERE email = ?', ['dis@test.local'])->fetch();
        $this->assertNull($row['totp_secret']);
        $this->assertNull($row['totp_enabled_at']);
        $this->assertNull($row['totp_recovery_codes']);
        @unlink($jar);
    }

    public function testRequire2faPolicyGatesApiUntilEnrolled(): void
    {
        DB::run("INSERT INTO settings (`key`, `value`) VALUES ('require_2fa', '\"all\"')
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $this->seedUser('viewer', 'forced@test.local', 'Secret123!');

        // El login abre sesión pero avisa del enrolamiento pendiente…
        $jar = tempnam(sys_get_temp_dir(), 'kmjar');
        $res = $this->request('POST', 'auth/login', ['email' => 'forced@test.local', 'password' => 'Secret123!'], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertTrue($res['json']['data']['totp_enroll_required']);

        // …y la API queda cortada salvo el propio enrolamiento.
        $res = $this->request('GET', 'forms', null, $jar);
        $this->assertSame(403, $res['status']);
        $this->assertSame('TOTP_ENROLL_REQUIRED', $res['json']['error']['code']);
        $this->assertSame(200, $this->request('GET', 'auth/me', null, $jar)['status']);
        $this->assertSame(200, $this->request('GET', 'profile/totp', null, $jar)['status']);

        // Tras enrolar, la API se abre.
        $this->enroll($jar);
        $this->assertSame(200, $this->request('GET', 'forms', null, $jar)['status']);
        @unlink($jar);
    }

    public function testRequire2faGuardrailAndAdminReset(): void
    {
        $adminId = $this->seedUser('admin', 'boss@test.local', 'Secret123!');
        $userId  = $this->seedUser('viewer', 'victim@test.local', 'Secret123!');
        $jar = $this->login('boss@test.local', 'Secret123!');

        // Guardarraíl: exigir 2FA sin tenerlo el propio admin → 422.
        $res = $this->request('PUT', 'admin/settings', ['require_2fa' => 'admins'], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);

        // Con su 2FA activo ya puede exigirlo.
        $this->enroll($jar);
        $res = $this->request('PUT', 'admin/settings', ['require_2fa' => 'admins'], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame('admins', $res['json']['data']['require_2fa']);

        // Reset por admin: enrola la víctima y resetéala; sus columnas quedan a NULL.
        $jarV = $this->login('victim@test.local', 'Secret123!');
        $this->enroll($jarV);
        // El listado de usuarios expone el flag 2FA.
        $list = $this->request('GET', 'admin/users', null, $jar)['json']['data'];
        $victim = array_values(array_filter($list, fn($u) => $u['email'] === 'victim@test.local'))[0];
        $this->assertTrue($victim['totp_enabled']);
        $res = $this->request('PUT', "admin/users/$userId",
            ['name' => 'Victim', 'email' => 'victim@test.local', 'role' => 'viewer', 'totp_reset' => true], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $row = DB::run('SELECT totp_secret, totp_enabled_at FROM users WHERE id = ?', [$userId])->fetch();
        $this->assertNull($row['totp_secret']);
        $this->assertNull($row['totp_enabled_at']);
        @unlink($jar);
        @unlink($jarV);
    }
}
