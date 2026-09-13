<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Política de contraseñas (lib/Password): longitud, lista común, trivialidad y datos personales. */
final class PasswordTest extends TestCase
{
    public function testShort(): void
    {
        $this->assertSame('short', Password::weakness('abc1234'));
    }

    public function testCommonWithPaddingIsStillCommon(): void
    {
        $this->assertSame('common', Password::weakness('password'));
        $this->assertSame('common', Password::weakness('Password123!'));
        $this->assertSame('common', Password::weakness('qwertyuiop'));
        $this->assertSame('common', Password::weakness('administrator'));
    }

    public function testTrivialSequencesAndRepeats(): void
    {
        $this->assertSame('trivial', Password::weakness('12345678'));
        $this->assertSame('trivial', Password::weakness('87654321'));
        $this->assertSame('trivial', Password::weakness('aaaaaaaa'));
        $this->assertSame('trivial', Password::weakness('12341234'));
        $this->assertSame('trivial', Password::weakness('abababab'));
    }

    public function testPersonalDataIsRejected(): void
    {
        $this->assertSame('personal', Password::weakness('mariaperez2026', ['maria.perez@ong.org', 'María Pérez']));
        $this->assertSame('personal', Password::weakness('xxPerezxx!', ['m@ong.org', 'María Pérez']));
        // Fragmentos cortos (<4) no cuentan: «ana» no invalida nada.
        $this->assertNull(Password::weakness('Banana-split-9', ['ana@ong.org', 'Ana']));
    }

    public function testAcceptable(): void
    {
        $this->assertNull(Password::weakness('Fresh-Start-77'));
        $this->assertSame('common', Password::weakness('Secret123!')); // «secret» + relleno
        $this->assertNull(Password::weakness('demo1234', ['admin@demo.org', 'Administrador']));
        $this->assertNull(Password::weakness('correct horse battery staple'));
        $this->assertNull(Password::weakness('Tr0ub4dor&3'));
        // El nombre «Demo» sí lo invalidaría: el dato personal es contextual.
        $this->assertSame('personal', Password::weakness('demo1234', ['admin@demo.org', 'Admin demo']));
    }

    public function testMessageCoversEveryReason(): void
    {
        foreach (['short', 'common', 'trivial', 'personal'] as $r) {
            $this->assertNotSame('', Password::message($r));
        }
    }
}
