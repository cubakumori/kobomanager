<?php

declare(strict_types=1);

/**
 * Catálogo de estados de revisión + resolución del estado inicial automático.
 * La BD de test trae los 4 built-ins sembrados (ver db/001_schema.sql).
 */
final class ReviewStatusTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ReviewStatus::flush(); // el catálogo se cachea en estático entre tests
    }

    public function testBuiltinsPresent(): void
    {
        $keys = ReviewStatus::keys();
        foreach (ReviewStatus::BUILTINS as $b) {
            $this->assertContains($b, $keys);
        }
    }

    public function testOpenKeysAreThePendingAndOnHold(): void
    {
        $open = ReviewStatus::openKeys();
        $this->assertContains('pending', $open);
        $this->assertContains('on_hold', $open);
        $this->assertNotContains('approved', $open);
        $this->assertNotContains('rejected', $open);
    }

    public function testIsAssignable(): void
    {
        $this->assertTrue(ReviewStatus::isAssignable('pending'));
        $this->assertTrue(ReviewStatus::isAssignable('approved'));
        $this->assertTrue(ReviewStatus::isAssignable('on_hold'));
        $this->assertFalse(ReviewStatus::isAssignable('does_not_exist'));
    }

    public function testIsValidFilterAcceptsAnyCatalogKey(): void
    {
        $this->assertTrue(ReviewStatus::isValidFilter('pending'));
        $this->assertTrue(ReviewStatus::isValidFilter('rejected'));
        $this->assertFalse(ReviewStatus::isValidFilter('nope'));
    }

    public function testCustomStatusActiveVsInactive(): void
    {
        DB::run(
            "INSERT INTO review_statuses (status_key, label, color, is_open, is_builtin, sort_order, active)
             VALUES ('duplicate', 'Duplicado', 'violet', 1, 0, 99, 1)"
        );
        ReviewStatus::flush();
        $this->assertTrue(ReviewStatus::isAssignable('duplicate'));
        $this->assertContains('duplicate', ReviewStatus::openKeys());

        // Desactivado: no se puede asignar, pero sigue siendo válido para filtrar.
        DB::run("UPDATE review_statuses SET active = 0 WHERE status_key = 'duplicate'");
        ReviewStatus::flush();
        $this->assertFalse(ReviewStatus::isAssignable('duplicate'));
        $this->assertTrue(ReviewStatus::isValidFilter('duplicate'));
        $this->assertNotContains('duplicate', array_column(ReviewStatus::active(), 'key'));
    }

    public function testInitialForNoneByDefault(): void
    {
        $formId = $this->makeForm();
        Settings::set('initial_review_status', '');
        ReviewStatus::flush();
        $this->assertNull(ReviewStatus::initialFor($formId));
    }

    public function testInitialForGlobalSetting(): void
    {
        $formId = $this->makeForm();
        Settings::set('initial_review_status', 'on_hold');
        ReviewStatus::flush();
        $this->assertSame('on_hold', ReviewStatus::initialFor($formId));
    }

    public function testInitialForPendingResolvesToNull(): void
    {
        $formId = $this->makeForm();
        Settings::set('initial_review_status', 'pending');
        ReviewStatus::flush();
        // 'pending' = sin auto-estado (no se crea fila).
        $this->assertNull(ReviewStatus::initialFor($formId));
    }

    public function testInitialForFormOverrideWinsOverGlobal(): void
    {
        $formId = $this->makeForm();
        Settings::set('initial_review_status', 'on_hold');
        DB::run('UPDATE forms SET initial_review_status = ? WHERE id = ?', ['approved', $formId]);
        ReviewStatus::flush();
        $this->assertSame('approved', ReviewStatus::initialFor($formId));
    }

    public function testInitialForInvalidKeyResolvesToNull(): void
    {
        $formId = $this->makeForm();
        Settings::set('initial_review_status', 'ghost_status');
        ReviewStatus::flush();
        $this->assertNull(ReviewStatus::initialFor($formId));
    }
}
