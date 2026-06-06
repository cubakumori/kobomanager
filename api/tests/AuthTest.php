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
}
