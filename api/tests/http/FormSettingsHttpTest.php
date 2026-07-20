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
    /** Esquema con select_one (team, prov, enum) y un select_multiple (langs). */
    private function schemaJson(): string
    {
        return json_encode([
            'fields' => [
                'team'  => ['leaf' => 'team', 'type' => 'select_one teams', 'list' => 'teams', 'multi' => false, 'label' => ['' => 'Equipo']],
                'prov'  => ['leaf' => 'prov', 'type' => 'select_one provs', 'list' => 'provs', 'multi' => false, 'label' => ['' => 'Provincia']],
                'enum'  => ['leaf' => 'enum', 'type' => 'select_one enums', 'list' => 'enums', 'multi' => false, 'label' => ['' => 'Encuestador']],
                'langs' => ['leaf' => 'langs', 'type' => 'select_multiple langs', 'list' => 'langs', 'multi' => true, 'label' => ['' => 'Idiomas']],
            ],
            'choices' => [
                'teams' => ['t1' => ['' => 'Uno'], 't2' => ['' => 'Dos']],
                'provs' => ['p1' => ['' => 'Norte'], 'p2' => ['' => 'Sur']],
                'enums' => ['e1' => ['' => 'Ana'], 'e2' => ['' => 'Luis']],
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

    public function testTeamGroupFieldValidation(): void
    {
        $userId = $this->seedUser('viewer', 'grouper@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $this->grant($userId, $formId, settings: true);
        $jar = $this->login('grouper@test.local', 'Secret123!');

        // Con campo de equipo, un select_one distinto se acepta como meta-equipo.
        $res = $this->request('PATCH', "admin/forms/$formId",
            ['stats_team_field' => 'team', 'stats_enumerator_field' => 'enum', 'team_group_field' => 'prov'], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame('prov', $res['json']['data']['team_group_field']);

        // Y el GET lo devuelve.
        $res = $this->request('GET', "admin/forms/$formId", null, $jar);
        $this->assertSame('prov', $res['json']['data']['team_group_field']);

        // Igual al campo de equipo o al de encuestador → 422 (misma cadena).
        $res = $this->request('PATCH', "admin/forms/$formId", ['team_group_field' => 'team'], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
        $res = $this->request('PATCH', "admin/forms/$formId", ['team_group_field' => 'enum'], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
        // Mover el EQUIPO encima del meta-equipo vigente también choca.
        $res = $this->request('PATCH', "admin/forms/$formId", ['stats_team_field' => 'prov'], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
        // Un select_multiple se rechaza (guard de tipo compartido).
        $res = $this->request('PATCH', "admin/forms/$formId", ['team_group_field' => 'langs'], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
        // Nada de lo anterior alteró la config guardada.
        $row = DB::run('SELECT stats_team_field, team_group_field FROM forms WHERE id = ?', [$formId])->fetch();
        $this->assertSame(['team', 'prov'], [$row['stats_team_field'], $row['team_group_field']]);

        // Quitar el campo de equipo anula la agrupación (un nivel por encima).
        $res = $this->request('PATCH', "admin/forms/$formId", ['stats_team_field' => null], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertNull($res['json']['data']['team_group_field']);
        $row = DB::run('SELECT team_group_field FROM forms WHERE id = ?', [$formId])->fetch();
        $this->assertNull($row['team_group_field']);
        @unlink($jar);
    }

    public function testTeamGroupDetectionEndpoint(): void
    {
        $userId = $this->seedUser('viewer', 'detector@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $this->grant($userId, $formId, settings: true);
        $jar = $this->login('detector@test.local', 'Secret123!');

        // Sin campo de equipo configurado la detección no arranca (422).
        $res = $this->request('GET', "admin/forms/$formId/team-group", null, $jar);
        $this->assertSame(422, $res['status'], $res['raw']);

        $this->request('PATCH', "admin/forms/$formId", ['stats_team_field' => 'team'], $jar);
        // Dos equipos consistentes con prov (dependencia funcional perfecta).
        foreach ([['t1', 'p1'], ['t1', 'p1'], ['t2', 'p2']] as [$tm, $pv]) {
            DB::run(
                'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP())',
                [$formId, 'uid_' . bin2hex(random_bytes(6)), json_encode(['team' => $tm, 'prov' => $pv])]
            );
        }

        // «Detectar meta-equipos»: prov encabeza el ranking.
        $res = $this->request('GET', "admin/forms/$formId/team-group", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertFalse($res['json']['data']['insufficient']);
        $this->assertSame('prov', $res['json']['data']['candidates'][0]['field']);

        // «Detectar problemas» sobre prov: sin conflictos.
        $res = $this->request('GET', "admin/forms/$formId/team-group?field=prov", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame([], $res['json']['data']['conflicts']);

        // Campo inexistente → 422; sin permiso «Ajustes» → 403.
        $res = $this->request('GET', "admin/forms/$formId/team-group?field=nope", null, $jar);
        $this->assertSame(422, $res['status']);
        $plainId = $this->seedUser('viewer', 'plain@test.local', 'Secret123!');
        $this->grant($plainId, $formId); // solo can_view
        $jar2 = $this->login('plain@test.local', 'Secret123!');
        $res = $this->request('GET', "admin/forms/$formId/team-group", null, $jar2);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
        @unlink($jar2);
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
