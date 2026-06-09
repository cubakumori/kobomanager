<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: formulario de contacto público (página «Apoyar»).
 * Es público (sin sesión). El email no se envía en test (RESEND_API_KEY vacío);
 * lo que se comprueba es que el mensaje se PERSISTE en contact_messages y que la
 * validación (campos obligatorios, email, rate-limit) responde correctamente.
 */
final class ContactHttpTest extends HttpTestCase
{
    public function testValidMessageIsStored(): void
    {
        $res = $this->request('POST', 'public/contact', [
            'name'    => 'María Test',
            'email'   => 'maria@ong.example',
            'org'     => 'ONG Ejemplo',
            'topic'   => 'using',
            'message' => 'Somos una organización que usa la app.',
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['success']);

        $row = DB::run('SELECT name, email, org, topic FROM contact_messages')->fetch();
        $this->assertSame('María Test', $row['name']);
        $this->assertSame('maria@ong.example', $row['email']);
        $this->assertSame('ONG Ejemplo', $row['org']);
        $this->assertSame('using', $row['topic']);
    }

    public function testUnknownTopicFallsBackToGeneral(): void
    {
        $res = $this->request('POST', 'public/contact', [
            'name'    => 'Sin Tema',
            'email'   => 'x@y.example',
            'topic'   => 'inventado',
            'message' => 'hola',
        ]);
        $this->assertSame(200, $res['status']);
        $row = DB::run('SELECT topic FROM contact_messages')->fetch();
        $this->assertSame('general', $row['topic']);
    }

    public function testMissingMessageIsRejected(): void
    {
        $res = $this->request('POST', 'public/contact', ['name' => 'X', 'email' => 'a@b.example']);
        $this->assertSame(422, $res['status']);
        $this->assertSame('VALIDATION_ERROR', $res['json']['error']['code']);
        $this->assertSame(0, (int) DB::run('SELECT COUNT(*) c FROM contact_messages')->fetch()['c']);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $res = $this->request('POST', 'public/contact', [
            'name'    => 'X',
            'email'   => 'no-es-email',
            'message' => 'hola',
        ]);
        $this->assertSame(422, $res['status']);
        $this->assertSame('VALIDATION_ERROR', $res['json']['error']['code']);
        $this->assertSame(0, (int) DB::run('SELECT COUNT(*) c FROM contact_messages')->fetch()['c']);
    }

    public function testRateLimitedAfterFiveSubmissions(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $ok = $this->request('POST', 'public/contact', [
                'name' => "User $i", 'email' => "u$i@x.example", 'message' => "msg $i",
            ]);
            $this->assertSame(200, $ok['status']);
        }
        // El sexto en la misma ventana es rechazado.
        $res = $this->request('POST', 'public/contact', [
            'name' => 'Sexto', 'email' => 'sexto@x.example', 'message' => 'demasiado',
        ]);
        $this->assertSame(429, $res['status']);
        $this->assertSame('RATE_LIMITED', $res['json']['error']['code']);
    }
}
