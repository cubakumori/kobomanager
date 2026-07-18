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
    /** Esquema con un select_one (team) y un select_multiple (langs). */
    private function schemaJson(): string
    {
        return json_encode([
            'fields' => [
                'team'  => ['leaf' => 'team', 'type' => 'select_one teams', 'list' => 'teams', 'multi' => false, 'label' => ['' => 'Equipo']],
                'langs' => ['leaf' => 'langs', 'type' => 'select_multiple langs', 'list' => 'langs', 'multi' => true, 'label' => ['' => 'Idiomas']],
            ],
            'choices' => [
                'teams' => ['t1' => ['' => 'Uno'], 't2' => ['' => 'Dos']],
                'langs' => ['es' => ['' => 'Español'], 'en' => ['' => 'Inglés']],
            ],
            'languages' => [null], 'meta_fields' => [], 'meta' => [],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testTeamFieldRejectsSelectMultiple(): void
    {
        $userId = $this->seedUser('viewer', 'teamsetter@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $this->grant($userId, $formId, settings: true);
        $jar = $this->login('teamsetter@test.local', 'Secret123!');

        // Un select_one monovaluado se acepta como campo de equipo.
        $res = $this->request('PATCH', "admin/forms/$formId", ['stats_team_field' => 'team'], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        // Un select_multiple se rechaza (una fila multivalor caería en varios equipos).
        $res = $this->request('PATCH', "admin/forms/$formId", ['stats_team_field' => 'langs'], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
        // El campo de equipo no cambió tras el rechazo.
        $row = DB::run('SELECT stats_team_field FROM forms WHERE id = ?', [$formId])->fetch();
        $this->assertSame('team', $row['stats_team_field']);
        @unlink($jar);
    }

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

        // Sensibilidad de duplicados: default 2 al leer, editable, 0 = desactivada.
        $this->assertSame(2, $res['json']['data']['qc_dup_min_answers']);
        $res = $this->request('PATCH', "admin/forms/$formId", ['qc_dup_min_answers' => 3], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(3, $res['json']['data']['qc_dup_min_answers']);
        $res = $this->request('PATCH', "admin/forms/$formId", ['qc_dup_min_answers' => 0], $jar);
        $this->assertNull($res['json']['data']['qc_dup_min_answers']);
        // Fuera de rango → 422.
        $res = $this->request('PATCH', "admin/forms/$formId", ['qc_dup_min_answers' => 99], $jar);
        $this->assertSame(422, $res['status']);

        // Índice de riesgo: null por defecto (opt-in), editable, 0 = desactivado, rango.
        $res = $this->request('GET', "admin/forms/$formId", null, $jar);
        $this->assertNull($res['json']['data']['risk_min_n']);
        $res = $this->request('PATCH', "admin/forms/$formId", ['risk_min_n' => 30], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(30, $res['json']['data']['risk_min_n']);
        $res = $this->request('PATCH', "admin/forms/$formId", ['risk_min_n' => 0], $jar);
        $this->assertNull($res['json']['data']['risk_min_n']);
        $res = $this->request('PATCH', "admin/forms/$formId", ['risk_min_n' => 999999], $jar);
        $this->assertSame(422, $res['status']);

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
