<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del MODO DEMO: servidor propio con KM_CONFIG →
 * tests/config.http.demo.php (DEMO_MODE=true sobre la misma BD de test).
 *
 * Comprueba el contrato completo: /config expone el modo, la denylist corta con
 * 403 DEMO_LOCKED las acciones sensibles (antes incluso de la autenticación), y
 * lo local/restaurable (revisión, shares, idioma…) sigue funcionando.
 */
final class DemoModeHttpTest extends HttpTestCase
{
    protected static function configFile(): string
    {
        return dirname(__DIR__) . '/config.http.demo.php';
    }

    public function testConfigExposesDemoMode(): void
    {
        $res = $this->request('GET', 'config');
        $this->assertSame(200, $res['status']);
        $cfg = $res['json']['data'];
        $this->assertTrue($cfg['demo_mode']);
        $this->assertSame(45, $cfg['demo_reset_minutes']);
        $this->assertSame('admin@demo.org / demo1234', $cfg['demo_login_admin']);
        $this->assertSame('viewer@demo.org / demo1234', $cfg['demo_login_viewer']);
    }

    public function testBlockedActionsReturnDemoLocked(): void
    {
        $uid = $this->seedUser('admin', 'admin@demo.local', 'Demo1234!');
        $jar = $this->login('admin@demo.local', 'Demo1234!');

        $blocked = [
            // Cuentas Kobo
            ['POST',   'admin/accounts'],
            ['PUT',    'admin/accounts/1'],
            ['DELETE', 'admin/accounts/1'],
            // Usuarios, contraseñas y sesiones
            ['POST',   'admin/users'],
            ['PUT',    "admin/users/$uid"],
            ['DELETE', "admin/users/$uid"],
            ['DELETE', "admin/users/$uid/sessions"],
            // Borrado de formularios (purga la caché local; degrada la demo)
            ['DELETE', 'admin/forms/1'],
            ['POST',   'profile/password'],
            ['DELETE', 'profile/sessions'],
            ['POST',   'auth/forgot-password'],
            ['POST',   'auth/reset-password'],
            // Ajustes globales
            ['PUT',    'admin/settings'],
            // Edición de envíos (escribe en Kobo real)
            ['PUT',    'submissions/algun-uid'],
            // Sync manual contra Kobo
            ['POST',   'admin/forms/sync'],
            ['POST',   'admin/forms/1/sync'],
            ['POST',   'forms/1/sync'],
        ];

        foreach ($blocked as [$method, $path]) {
            $res = $this->request($method, $path, ['x' => 1], $jar);
            $this->assertSame(403, $res['status'], "$method /$path debería estar bloqueado en demo");
            $this->assertSame('DEMO_LOCKED', $res['json']['error']['code'] ?? null, "$method /$path");
        }
    }

    public function testReviewStillAllowed(): void
    {
        $this->seedUser('admin', 'admin@demo.local', 'Demo1234!');
        $jar   = $this->login('admin@demo.local', 'Demo1234!');
        $accId = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'sub-demo-1', ['_id' => 1, 'nombre' => 'Ana']);

        $res = $this->request('POST', 'submissions/sub-demo-1/review', ['status' => 'approved'], $jar);
        $this->assertSame(201, $res['status'], 'la revisión debe seguir permitida en demo: ' . $res['raw']);
        $this->assertSame('approved', $res['json']['data']['review_status']);

        // En demo NO se empuja a Kobo: la línea base queda intacta (NULL), prueba de que
        // la rama de push no se ejecutó (solo ella fija kobo_validation_seen).
        $seen = DB::run('SELECT kobo_validation_seen FROM submissions_cache WHERE submission_uid = ?', ['sub-demo-1'])->fetch();
        $this->assertNull($seen['kobo_validation_seen']);
    }

    public function testLocalActionsStillAllowed(): void
    {
        $this->seedUser('admin', 'admin@demo.local', 'Demo1234!');
        $jar   = $this->login('admin@demo.local', 'Demo1234!');
        $accId = $this->seedAccount();
        $formId = $this->seedForm($accId);

        // Idioma del perfil (preferencia local, el reset la restaura).
        $res = $this->request('PUT', 'profile', ['locale' => 'en'], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);

        // Crear un enlace compartido (local y revocable; el reset lo restaura).
        $res = $this->request('POST', 'admin/shares', ['form_id' => $formId, 'expose_list' => true], $jar);
        $this->assertSame(201, $res['status'], $res['raw']);
        $this->assertNotEmpty($res['json']['data']['token'] ?? null);
    }
}
