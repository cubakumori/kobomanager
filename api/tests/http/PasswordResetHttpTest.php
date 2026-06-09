<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: recuperación de contraseña (forgot → token → reset).
 * El email no se envía en test (RESEND_API_KEY vacío); el token en claro no aparece en
 * la respuesta, así que para probar el reset se siembra un token conocido en BD.
 */
final class PasswordResetHttpTest extends HttpTestCase
{
    public function testForgotIsGenericAndDoesNothingWhenDisabled(): void
    {
        $uid = $this->seedUser('viewer', 'p@test.local', 'Secret123!');
        // Flujo OFF (sin setting) → respuesta genérica, sin crear token.
        $res = $this->request('POST', 'auth/forgot-password', ['email' => 'p@test.local']);
        $this->assertSame(200, $res['status']);
        $count = DB::run('SELECT COUNT(*) c FROM password_resets WHERE user_id = ?', [$uid])->fetch();
        $this->assertSame(0, (int) $count['c']);
    }

    public function testForgotCreatesTokenWhenEnabled(): void
    {
        $uid = $this->seedUser('viewer', 'p@test.local', 'Secret123!');
        $this->setSetting('password_reset_enabled', true);

        $res = $this->request('POST', 'auth/forgot-password', ['email' => 'p@test.local']);
        $this->assertSame(200, $res['status']);
        $count = DB::run('SELECT COUNT(*) c FROM password_resets WHERE user_id = ?', [$uid])->fetch();
        $this->assertSame(1, (int) $count['c']);
    }

    public function testForgotUnknownEmailStillGeneric(): void
    {
        $this->setSetting('password_reset_enabled', true);
        $res = $this->request('POST', 'auth/forgot-password', ['email' => 'nobody@test.local']);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['success']);
    }

    public function testResetWithValidTokenChangesPasswordAndClearsSessions(): void
    {
        $uid = $this->seedUser('viewer', 'p@test.local', 'OldSecret123!');
        $this->setSetting('password_reset_enabled', true);

        // Sembrar un token conocido (la app guarda solo el hash).
        $token = bin2hex(random_bytes(16));
        DB::run(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)',
            [$uid, hash('sha256', $token), '127.0.0.1']
        );

        // GET valida el token.
        $check = $this->request('GET', 'auth/reset-password?token=' . $token);
        $this->assertTrue($check['json']['data']['valid']);

        // POST fija la nueva contraseña.
        $res = $this->request('POST', 'auth/reset-password', ['token' => $token, 'password' => 'NewSecret123!']);
        $this->assertSame(200, $res['status']);

        // La contraseña vieja ya no sirve; la nueva sí.
        $this->assertSame(401, $this->request('POST', 'auth/login', ['email' => 'p@test.local', 'password' => 'OldSecret123!'])['status']);
        $this->assertSame(200, $this->request('POST', 'auth/login', ['email' => 'p@test.local', 'password' => 'NewSecret123!'])['status']);

        // El token quedó consumido.
        $used = DB::run('SELECT used_at FROM password_resets WHERE user_id = ?', [$uid])->fetch();
        $this->assertNotNull($used['used_at']);
    }

    public function testResetWithInvalidTokenIsRejected(): void
    {
        $this->setSetting('password_reset_enabled', true);
        $res = $this->request('POST', 'auth/reset-password', ['token' => 'deadbeef', 'password' => 'NewSecret123!']);
        $this->assertSame(400, $res['status']);
        $this->assertSame('RESET_TOKEN_INVALID', $res['json']['error']['code']);
    }
}
