<?php

declare(strict_types=1);

/**
 * Tests de los enlaces de solo lectura (lib/ShareLink): resolución con
 * revocación/caducidad, regla de scoping, contraseña y tickets firmados.
 */
final class ShareLinkTest extends DbTestCase
{
    /** Inserta un enlace y devuelve [token, id]. */
    private function makeShare(int $formId, array $opts = []): array
    {
        $token = ShareLink::generateToken();
        DB::run(
            'INSERT INTO share_links
                (token, form_id, created_by, label, expose_list, expose_detail, expose_map,
                 expose_attachments, row_filter, password_hash, expires_at, revoked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $token, $formId, $opts['created_by'] ?? $this->makeUser('admin'),
                $opts['label'] ?? null,
                $opts['expose_list'] ?? 1, $opts['expose_detail'] ?? 1, $opts['expose_map'] ?? 0,
                $opts['expose_attachments'] ?? 0,
                isset($opts['row_filter']) ? json_encode($opts['row_filter']) : null,
                $opts['password_hash'] ?? null,
                $opts['expires_at'] ?? null,
                $opts['revoked_at'] ?? null,
            ]
        );
        return [$token, (int) DB::conn()->lastInsertId()];
    }

    public function testGenerateTokenIsUrlSafeAndUnique(): void
    {
        $a = ShareLink::generateToken();
        $b = ShareLink::generateToken();
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $a);
        $this->assertGreaterThanOrEqual(24, strlen($a));
    }

    public function testResolveActiveLink(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, ['label' => 'Público']);
        $link = ShareLink::resolve($token);
        $this->assertNotNull($link);
        $this->assertSame($formId, (int) $link['form_id']);
        $this->assertSame('Público', $link['label']);
    }

    public function testResolveUnknownTokenReturnsNull(): void
    {
        $this->assertNull(ShareLink::resolve('does-not-exist'));
        $this->assertNull(ShareLink::resolve(''));
    }

    public function testResolveRevokedReturnsNull(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, ['revoked_at' => date('Y-m-d H:i:s')]);
        $this->assertNull(ShareLink::resolve($token));
    }

    public function testResolveExpiredReturnsNull(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, ['expires_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->assertNull(ShareLink::resolve($token));
    }

    public function testResolveFutureExpiryIsActive(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, ['expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertNotNull(ShareLink::resolve($token));
    }

    public function testResolveInactiveFormReturnsNull(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId);
        DB::run('UPDATE forms SET active = 0 WHERE id = ?', [$formId]);
        $this->assertNull(ShareLink::resolve($token));
    }

    public function testRuleReflectsRowFilter(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, [
            'row_filter' => ['conditions' => [['field' => 'region', 'values' => ['norte']]]],
        ]);
        $link = ShareLink::resolve($token);
        // El formato antiguo del enlace se lee y canonicaliza a grupos (retrocompat).
        $this->assertSame(
            ['match' => 'all', 'groups' => [
                ['match' => 'all', 'conditions' => [
                    ['field' => 'region', 'op' => 'in', 'values' => ['norte']],
                ]],
            ]],
            ShareLink::rule($link)
        );
    }

    public function testRuleNullWhenNoFilter(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId);
        $this->assertNull(ShareLink::rule(ShareLink::resolve($token)));
    }

    public function testPasswordVerification(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, ['password_hash' => password_hash('s3cret', PASSWORD_DEFAULT)]);
        $link = ShareLink::resolve($token);
        $this->assertTrue(ShareLink::hasPassword($link));
        $this->assertTrue(ShareLink::verifyPassword($link, 's3cret'));
        $this->assertFalse(ShareLink::verifyPassword($link, 'wrong'));
    }

    public function testNoPasswordAlwaysVerifies(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId);
        $link = ShareLink::resolve($token);
        $this->assertFalse(ShareLink::hasPassword($link));
        $this->assertTrue(ShareLink::verifyPassword($link, 'anything'));
    }

    public function testTicketRoundTrip(): void
    {
        $ticket = ShareLink::issueTicket('tok-abc');
        $this->assertTrue(ShareLink::verifyTicket($ticket, 'tok-abc'));
    }

    public function testTicketRejectsWrongTokenAndTamper(): void
    {
        $ticket = ShareLink::issueTicket('tok-abc');
        $this->assertFalse(ShareLink::verifyTicket($ticket, 'other-token'));
        $this->assertFalse(ShareLink::verifyTicket($ticket . 'x', 'tok-abc'));
        $this->assertFalse(ShareLink::verifyTicket(null, 'tok-abc'));
        $this->assertFalse(ShareLink::verifyTicket('garbage', 'tok-abc'));
    }

    public function testRecordAccessIncrements(): void
    {
        $formId = $this->makeForm();
        [, $id] = $this->makeShare($formId);
        ShareLink::recordAccess($id);
        ShareLink::recordAccess($id);
        $row = DB::run('SELECT access_count, last_accessed_at FROM share_links WHERE id = ?', [$id])->fetch();
        $this->assertSame(2, (int) $row['access_count']);
        $this->assertNotNull($row['last_accessed_at']);
    }

    // ---- Adjuntos (capacidad 'attachments') ----
    // Solo se prueba el camino de ÉXITO: las ramas de error de requireAccess
    // llaman a ErrorResponse::send(), que hace exit() y terminaría el proceso de
    // test (igual que el resto de la suite, no se cubren esas ramas).

    public function testResolveExposesAttachmentsColumn(): void
    {
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, ['expose_attachments' => 1]);
        $link = ShareLink::resolve($token);
        $this->assertSame(1, (int) $link['expose_attachments']);
    }

    public function testRequireAccessAttachmentsReturnsLinkWhenExposed(): void
    {
        Settings::set('share_attachments_policy', 'require_password');
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, ['expose_attachments' => 1]);
        $link = ShareLink::requireAccess($token, 'attachments');
        $this->assertSame($formId, (int) $link['form_id']);
    }

    public function testRequireAccessAttachmentsWithPasswordAcceptsTicket(): void
    {
        Settings::set('share_attachments_policy', 'require_password');
        $formId = $this->makeForm();
        [$token] = $this->makeShare($formId, [
            'expose_attachments' => 1,
            'password_hash'      => password_hash('s3cret', PASSWORD_DEFAULT),
        ]);
        // Ticket válido vía cabecera (como lo enviaría el navegador en ?k= o header).
        $_SERVER['HTTP_X_SHARE_TICKET'] = ShareLink::issueTicket($token);
        try {
            $link = ShareLink::requireAccess($token, 'attachments');
            $this->assertSame($formId, (int) $link['form_id']);
        } finally {
            unset($_SERVER['HTTP_X_SHARE_TICKET']);
        }
    }
}
