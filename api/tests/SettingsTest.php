<?php

declare(strict_types=1);

/** Ajustes globales (tabla settings). */
final class SettingsTest extends DbTestCase
{
    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('xyz', Settings::get('no_existe', 'xyz'));
    }

    public function testSetAndGetRoundTripJson(): void
    {
        Settings::set('mi_clave', ['a' => 1, 'b' => true]);
        $this->assertSame(['a' => 1, 'b' => true], Settings::get('mi_clave'));
    }

    public function testDefaults(): void
    {
        $this->assertSame(['deployed'], Settings::syncStatuses());
        $this->assertSame('es', Settings::defaultLocale());
        $this->assertSame('labels', Settings::labelMode());
        $this->assertFalse(Settings::passwordResetEnabled());
    }

    public function testSyncStatusesSanitizes(): void
    {
        Settings::set('sync_deployment_statuses', ['deployed', 'bogus', 'draft']);
        $this->assertSame(['deployed', 'draft'], Settings::syncStatuses());
        // Lista vacía/!válida → vuelve al valor por defecto.
        Settings::set('sync_deployment_statuses', ['bogus']);
        $this->assertSame(['deployed'], Settings::syncStatuses());
    }

    public function testPasswordResetToggle(): void
    {
        Settings::set('password_reset_enabled', true);
        $this->assertTrue(Settings::passwordResetEnabled());
    }

    public function testViewerActionsDefaultAllFalse(): void
    {
        $va = Settings::viewerActions();
        $this->assertSame(['enketo', 'update', 'resync', 'login'], array_keys($va));
        $this->assertSame([false, false, false, false], array_values($va));
    }

    public function testViewerActionsReflectStoredFlags(): void
    {
        Settings::set('viewer_can_update', true);
        Settings::set('viewer_can_resync', true);
        $va = Settings::viewerActions();
        $this->assertTrue($va['update']);
        $this->assertTrue($va['resync']);
        $this->assertFalse($va['enketo']);
        $this->assertFalse($va['login']);
    }

    public function testFieldTruncateDefault(): void
    {
        $ft = Settings::fieldTruncate();
        $this->assertFalse($ft['enabled']);
        $this->assertSame(24, $ft['chars']);
    }

    public function testFieldTruncateReflectsStoredAndClamps(): void
    {
        Settings::set('field_truncate_enabled', true);
        // Por debajo del mínimo → se sube al mínimo.
        Settings::set('field_truncate_chars', 2);
        $ft = Settings::fieldTruncate();
        $this->assertTrue($ft['enabled']);
        $this->assertSame(Settings::FIELD_TRUNCATE_MIN, $ft['chars']);
        // Por encima del máximo → se baja al máximo.
        Settings::set('field_truncate_chars', 9999);
        $this->assertSame(Settings::FIELD_TRUNCATE_MAX, Settings::fieldTruncate()['chars']);
        // Dentro de rango → se respeta.
        Settings::set('field_truncate_chars', 30);
        $this->assertSame(30, Settings::fieldTruncate()['chars']);
    }
}
