<?php

declare(strict_types=1);

/** Permisos por formulario y ciclo de sesión JWT. */
final class AuthTest extends DbTestCase
{
    // ---------- Permisos (canForm) ----------

    public function testAdminBypassesFormPermissions(): void
    {
        $admin = ['id' => $this->makeUser('admin'), 'role' => 'admin'];
        $formId = $this->makeForm();
        $this->assertTrue(Auth::canForm($admin, $formId, 'view'));
        $this->assertTrue(Auth::canForm($admin, $formId, 'edit'));
        $this->assertTrue(Auth::canForm($admin, $formId, 'validate'));
    }

    public function testViewerNeedsExplicitPermission(): void
    {
        $uid = $this->makeUser('viewer');
        $viewer = ['id' => $uid, 'role' => 'viewer'];
        $formId = $this->makeForm();

        // Sin fila de permisos → nada permitido.
        $this->assertFalse(Auth::canForm($viewer, $formId, 'view'));

        DB::run(
            'INSERT INTO user_form_permissions (user_id, form_id, can_view, can_edit, can_validate) VALUES (?, ?, 1, 0, 1)',
            [$uid, $formId]
        );
        $this->assertTrue(Auth::canForm($viewer, $formId, 'view'));
        $this->assertFalse(Auth::canForm($viewer, $formId, 'edit'));
        $this->assertTrue(Auth::canForm($viewer, $formId, 'validate'));
    }

    public function testCanFormUnknownCapabilityIsFalse(): void
    {
        $viewer = ['id' => $this->makeUser('viewer'), 'role' => 'viewer'];
        $this->assertFalse(Auth::canForm($viewer, $this->makeForm(), 'delete'));
    }

    // ---------- Sesión JWT ----------

    private function issueFor(int $uid): string
    {
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        return Auth::issue(['id' => $uid]);
    }

    public function testIssueThenCurrentUser(): void
    {
        $uid = $this->makeUser('admin', true, 'admin@test.local');
        $_COOKIE[COOKIE_NAME] = $this->issueFor($uid);

        $u = Auth::currentUser();
        $this->assertNotNull($u);
        $this->assertSame($uid, $u['id']);
        $this->assertSame('admin', $u['role']);
        $this->assertSame('admin@test.local', $u['email']);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $uid = $this->makeUser();
        $_COOKIE[COOKIE_NAME] = $this->issueFor($uid) . 'x';
        $this->assertNull(Auth::currentUser());
    }

    public function testNoCookieIsNull(): void
    {
        $_COOKIE = [];
        $this->assertNull(Auth::currentUser());
    }

    public function testRevokedSessionIsRejected(): void
    {
        $uid = $this->makeUser();
        $_COOKIE[COOKIE_NAME] = $this->issueFor($uid);
        $this->assertNotNull(Auth::currentUser());

        // Simula cierre de sesión remoto (revocación).
        DB::run('DELETE FROM user_sessions WHERE user_id = ?', [$uid]);
        $this->assertNull(Auth::currentUser());
    }

    public function testInactiveUserIsRejected(): void
    {
        $uid = $this->makeUser('viewer', true);
        $_COOKIE[COOKIE_NAME] = $this->issueFor($uid);
        $this->assertNotNull(Auth::currentUser());

        DB::run('UPDATE users SET active = 0 WHERE id = ?', [$uid]);
        $this->assertNull(Auth::currentUser());
    }

    public function testLogoutInvalidatesSession(): void
    {
        $uid = $this->makeUser();
        $_COOKIE[COOKIE_NAME] = $this->issueFor($uid);
        $this->assertNotNull(Auth::currentUser());

        Auth::logout();
        $this->assertNull(Auth::currentUser());
    }

    // ---------- Sesión deslizante / tope absoluto ----------

    private function expiresTs(int $uid): int
    {
        $row = DB::run('SELECT UNIX_TIMESTAMP(expires_at) AS e FROM user_sessions WHERE user_id = ?', [$uid])->fetch();
        return (int) $row['e'];
    }

    public function testSlidingSessionExtendsWhenNearExpiry(): void
    {
        $uid = $this->makeUser();
        $jti = bin2hex(random_bytes(16));
        $now = time();

        // JWT con poca vida (por debajo del umbral de refresh) y sesión que aún no expira.
        $enc = new ReflectionMethod(Auth::class, 'jwtEncode'); // accesible sin setAccessible desde PHP 8.1
        $jwt = $enc->invoke(null, ['sub' => $uid, 'jti' => $jti, 'iat' => $now - 60, 'exp' => $now + 60]);

        DB::run(
            'INSERT INTO user_sessions (user_id, token_id, expires_at, last_activity, created_at)
             VALUES (?, ?, FROM_UNIXTIME(?), NOW(), NOW())',
            [$uid, $jti, $now + 60]
        );
        $_COOKIE[COOKIE_NAME] = $jwt;

        $this->assertNotNull(Auth::currentUser());
        // El refresh deslizante debe haber empujado expires_at cerca de NOW()+JWT_TTL.
        $this->assertGreaterThan($now + (int) (SESSION_REFRESH_THRESHOLD), $this->expiresTs($uid));
    }

    public function testNoRefreshWhenPlentyOfLifeRemains(): void
    {
        $uid = $this->makeUser();
        $_COOKIE[COOKIE_NAME] = $this->issueFor($uid); // exp = NOW()+JWT_TTL, lejos del umbral
        $before = $this->expiresTs($uid);

        $this->assertNotNull(Auth::currentUser());
        // No se renueva (margen suficiente): expires_at no debe crecer.
        $this->assertSame($before, $this->expiresTs($uid));
    }

    public function testAbsoluteCapKillsSession(): void
    {
        $uid = $this->makeUser();
        $_COOKIE[COOKIE_NAME] = $this->issueFor($uid);
        $this->assertNotNull(Auth::currentUser());

        // Mueve el inicio de sesión más allá del tope absoluto.
        DB::run(
            'UPDATE user_sessions SET created_at = FROM_UNIXTIME(?) WHERE user_id = ?',
            [time() - SESSION_ABSOLUTE_TTL - 10, $uid]
        );

        $this->assertNull(Auth::currentUser());
        // La sesión caducada por tope absoluto se elimina.
        $this->assertFalse(DB::run('SELECT id FROM user_sessions WHERE user_id = ?', [$uid])->fetch());
    }
}
