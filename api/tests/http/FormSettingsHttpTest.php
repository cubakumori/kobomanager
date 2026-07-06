<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: permiso «Ajustes» (can_settings) sobre admin/forms/{id}.
 * Un usuario con can_settings puede leer/editar los ajustes de ESE formulario
 * (desglose por equipo + umbrales del control de calidad), pero NO borrarlo ni
 * tocar los de otro formulario; sin el permiso, GET/PATCH devuelven 403.
 */
final class FormSettingsHttpTest extends HttpTestCase
{
    public function testViewerWithoutSettingsPermissionGets403(): void
    {
        $userId = $this->seedUser('viewer', 'viewer@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->grant($userId, $formId); // solo can_view
        $jar = $this->login('viewer@test.local', 'Secret123!');

        $res = $this->request('GET', "admin/forms/$formId", null, $jar);
        $this->assertSame(403, $res['status']);
        $res = $this->request('PATCH', "admin/forms/$formId", ['qc_min_duration' => 10], $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }

    public function testSettingsPermissionAllowsGetAndPatchButNotDelete(): void
    {
        $userId = $this->seedUser('viewer', 'setter@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $otherFormId = $this->seedForm($accId, 'asset_other');
        $this->grant($userId, $formId, settings: true);
        $jar = $this->login('setter@test.local', 'Secret123!');

        // Lee y edita los ajustes de SU formulario.
        $res = $this->request('GET', "admin/forms/$formId", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertArrayHasKey('qc_min_duration', $res['json']['data']);

        $res = $this->request('PATCH', "admin/forms/$formId", ['qc_min_duration' => 10, 'qc_min_gap' => null], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $row = DB::run('SELECT qc_min_duration, qc_min_gap FROM forms WHERE id = ?', [$formId])->fetch();
        $this->assertSame(10, (int) $row['qc_min_duration']);
        $this->assertNull($row['qc_min_gap']);

        // 0 equivale a vacío: desactiva la comprobación (se guarda NULL).
        $res = $this->request('PATCH', "admin/forms/$formId", ['qc_min_duration' => 0], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertNull($res['json']['data']['qc_min_duration']);
        $row = DB::run('SELECT qc_min_duration FROM forms WHERE id = ?', [$formId])->fetch();
        $this->assertNull($row['qc_min_duration']);

        // Ni borrar el suyo, ni leer el de otro formulario.
        $res = $this->request('DELETE', "admin/forms/$formId", null, $jar);
        $this->assertSame(403, $res['status']);
        $this->assertTrue((bool) DB::run('SELECT 1 FROM forms WHERE id = ?', [$formId])->fetch());
        $res = $this->request('GET', "admin/forms/$otherFormId", null, $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }

    public function testSettingsPermissionTravelsInFormsListAndQuality(): void
    {
        $userId = $this->seedUser('viewer', 'lister@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->grant($userId, $formId, settings: true);
        $jar = $this->login('lister@test.local', 'Secret123!');

        $res = $this->request('GET', 'forms', null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['json']['data'][0]['can_settings']);

        $res = $this->request('GET', "forms/$formId/quality", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertTrue($res['json']['data']['can_settings']);
        $this->assertFalse($res['json']['data']['can_validate']);
        @unlink($jar);
    }
}
