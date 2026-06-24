<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mapeo entre el estado de revisión interno y el `_validation_status` nativo de Kobo.
 */
final class ValidationStatusTest extends TestCase
{
    public function testToKobo(): void
    {
        $this->assertSame('validation_status_approved', ValidationStatus::toKobo('approved'));
        $this->assertSame('validation_status_not_approved', ValidationStatus::toKobo('rejected'));
        $this->assertSame('validation_status_on_hold', ValidationStatus::toKobo('on_hold'));
        $this->assertSame('', ValidationStatus::toKobo('pending'));
        $this->assertSame('', ValidationStatus::toKobo('desconocido'));
    }

    public function testFromKobo(): void
    {
        $this->assertSame('approved', ValidationStatus::fromKobo('validation_status_approved'));
        $this->assertSame('rejected', ValidationStatus::fromKobo('validation_status_not_approved'));
        $this->assertSame('on_hold', ValidationStatus::fromKobo('validation_status_on_hold'));
        // Sin estado / vacío / null / uid desconocido → pending.
        $this->assertSame('pending', ValidationStatus::fromKobo(''));
        $this->assertSame('pending', ValidationStatus::fromKobo(null));
        $this->assertSame('pending', ValidationStatus::fromKobo('validation_status_inventado'));
    }

    public function testRoundTrip(): void
    {
        foreach (['pending', 'approved', 'rejected', 'on_hold'] as $s) {
            $this->assertSame($s, ValidationStatus::fromKobo(ValidationStatus::toKobo($s)));
        }
    }

    public function testUidFromPayload(): void
    {
        $this->assertSame(
            'validation_status_approved',
            ValidationStatus::uidFromPayload(['_validation_status' => ['uid' => 'validation_status_approved', 'label' => 'Approved']])
        );
        $this->assertSame('', ValidationStatus::uidFromPayload(['_validation_status' => []]));
        $this->assertSame('', ValidationStatus::uidFromPayload([]));
    }
}
