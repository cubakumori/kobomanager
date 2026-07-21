<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del plan de muestra (admin/forms/{id}/sample-plan) y del panel
 * (forms/{id}/sample). Cubre: el permiso jerárquico «Muestra» (el plan exige
 * can_sample; «Ajustes» a secas recibe 403 aquí pero sigue abriendo el resto de
 * ajustes; el guardado de permisos normaliza can_sample ⇒ can_settings), el guard
 * de que el campo de muestreo sea de opción única (select_one, no select_multiple
 * ni inexistente), el reemplazo del plan vigente + snapshot en el histórico, y que
 * el panel exija can_view.
 */
final class SamplePlanHttpTest extends HttpTestCase
{
    /** select_one team/age + select_multiple langs. */
    private function schemaJson(): string
    {
        return json_encode([
            'fields' => [
                'team'  => ['leaf' => 'team', 'type' => 'select_one teams', 'list' => 'teams', 'multi' => false, 'label' => ['' => 'Equipo']],
                'age'   => ['leaf' => 'age', 'type' => 'select_one ages', 'list' => 'ages', 'multi' => false, 'label' => ['' => 'Edad']],
                'langs' => ['leaf' => 'langs', 'type' => 'select_multiple langs', 'list' => 'langs', 'multi' => true, 'label' => ['' => 'Idiomas']],
            ],
            'choices' => [
                'teams' => ['t1' => ['' => 'Uno'], 't2' => ['' => 'Dos']],
                'ages'  => ['a1' => ['' => '18-29'], 'a2' => ['' => '30-44']],
                'langs' => ['es' => ['' => 'Español'], 'en' => ['' => 'Inglés']],
            ],
            'languages' => [null], 'meta_fields' => [], 'meta' => [],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testViewerWithoutSettingsGets403(): void
    {
        $userId = $this->seedUser('viewer', 'sp_viewer@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $this->grant($userId, $formId); // solo can_view
        $jar = $this->login('sp_viewer@test.local', 'Secret123!');

        $this->assertSame(403, $this->request('GET', "admin/forms/$formId/sample-plan", null, $jar)['status']);
        $this->assertSame(403, $this->request('PUT', "admin/forms/$formId/sample-plan", ['sample_field' => 'age', 'cells' => []], $jar)['status']);
        @unlink($jar);
    }

    public function testSettingsOnlyUserGets403OnPlanButKeepsSettings(): void
    {
        $userId = $this->seedUser('viewer', 'sp_settings@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $this->grant($userId, $formId, settings: true); // «Ajustes» sin «Muestra»
        $jar = $this->login('sp_settings@test.local', 'Secret123!');

        // El plan de muestra exige el permiso jerárquico «Muestra».
        $this->assertSame(403, $this->request('GET', "admin/forms/$formId/sample-plan", null, $jar)['status']);
        $this->assertSame(403, $this->request('PUT', "admin/forms/$formId/sample-plan", ['sample_field' => 'age', 'cells' => []], $jar)['status']);
        // Pero el resto de Ajustes sigue abierto, y el GET informa can_sample=false.
        $res = $this->request('GET', "admin/forms/$formId", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertFalse($res['json']['data']['can_sample']);
        @unlink($jar);
    }

    public function testSampleUserEditsPlanAndImpliedSettings(): void
    {
        $userId = $this->seedUser('viewer', 'sp_sample@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $this->grant($userId, $formId, sample: true); // «Muestra» (implica «Ajustes»)
        $jar = $this->login('sp_sample@test.local', 'Secret123!');

        $res = $this->request('PUT', "admin/forms/$formId/sample-plan", [
            'sample_field' => 'age',
            'cells' => [['team_value' => 't1', 'sample_value' => 'a1', 'target' => 5]],
        ], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        // «Muestra» implica «Ajustes»: el GET de ajustes funciona y lo declara.
        $res = $this->request('GET', "admin/forms/$formId", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertTrue($res['json']['data']['can_sample']);
        $this->assertSame(1, $res['json']['data']['sample_target_count']);
        @unlink($jar);
    }

    public function testPermissionsPutNormalizesSampleImpliesSettings(): void
    {
        $adminId = $this->seedUser('admin', 'sp_padmin@test.local', 'Secret123!');
        $userId  = $this->seedUser('viewer', 'sp_puser@test.local', 'Secret123!');
        $accId   = $this->seedAccount();
        $formId  = $this->seedForm($accId, null, $this->schemaJson());
        $jar = $this->login('sp_padmin@test.local', 'Secret123!');

        // Guardar SOLO can_sample: el servidor debe normalizar can_settings=1 (y can_view=1).
        $res = $this->request('PUT', 'admin/permissions', [
            'user_id' => $userId,
            'permissions' => [['form_id' => $formId, 'can_sample' => true]],
        ], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $row = DB::run('SELECT can_view, can_settings, can_sample FROM user_form_permissions WHERE user_id = ? AND form_id = ?', [$userId, $formId])->fetch();
        $this->assertSame(1, (int) $row['can_sample']);
        $this->assertSame(1, (int) $row['can_settings']);
        $this->assertSame(1, (int) $row['can_view']);
        // Y el GET de permisos lo devuelve como booleano.
        $res = $this->request('GET', "admin/permissions?user_id=$userId", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $mine = array_values(array_filter($res['json']['data'], fn($p) => $p['form_id'] === $formId))[0];
        $this->assertTrue($mine['can_sample']);
        $this->assertTrue($mine['can_settings']);
        @unlink($jar);
    }

    public function testPlanRequiresSelectOneField(): void
    {
        $userId = $this->seedUser('admin', 'sp_admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $jar = $this->login('sp_admin@test.local', 'Secret123!');

        // select_multiple → 422.
        $res = $this->request('PUT', "admin/forms/$formId/sample-plan", ['sample_field' => 'langs', 'cells' => []], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
        // Campo inexistente → 422.
        $res = $this->request('PUT', "admin/forms/$formId/sample-plan", ['sample_field' => 'nope', 'cells' => []], $jar);
        $this->assertSame(422, $res['status']);
        // Secundario select_multiple → 422.
        $res = $this->request('PUT', "admin/forms/$formId/sample-plan", ['sample_field' => 'age', 'sample_field2' => 'langs', 'cells' => []], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
        // El plan no se guardó (sample_field sigue vacío).
        $row = DB::run('SELECT sample_field FROM forms WHERE id = ?', [$formId])->fetch();
        $this->assertNull($row['sample_field']);
        @unlink($jar);
    }

    public function testPlanRejectsDuplicateFields(): void
    {
        $userId = $this->seedUser('admin', 'sp_dup@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $jar = $this->login('sp_dup@test.local', 'Secret123!');

        // Un campo no puede ser a la vez principal y secundario.
        $res = $this->request('PUT', "admin/forms/$formId/sample-plan", ['sample_field' => 'age', 'sample_field2' => 'age', 'cells' => []], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
        // Un campo distinto como secundario sí se acepta.
        $res = $this->request('PUT', "admin/forms/$formId/sample-plan", ['sample_field' => 'age', 'sample_field2' => 'team', 'cells' => []], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        @unlink($jar);
    }

    public function testPutReplacesPlanAndArchivesSnapshot(): void
    {
        $userId = $this->seedUser('admin', 'sp_admin2@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $jar = $this->login('sp_admin2@test.local', 'Secret123!');

        // Primer guardado: dos celdas (una con target 0, que NO se persiste).
        $res = $this->request('PUT', "admin/forms/$formId/sample-plan", [
            'sample_field' => 'age', 'denominator' => 'approved_pending',
            'cells' => [
                ['team_value' => 't1', 'sample_value' => 'a1', 'target' => 10],
                ['team_value' => 't1', 'sample_value' => 'a2', 'target' => 0],
            ],
        ], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(1, $res['json']['data']['cells']); // solo la de target > 0
        $this->assertSame('approved_pending', $res['json']['data']['denominator']);
        $this->assertSame(1, (int) DB::run('SELECT COUNT(*) c FROM sample_targets WHERE form_id = ?', [$formId])->fetch()['c']);
        $this->assertSame(1, (int) DB::run('SELECT COUNT(*) c FROM sample_target_history WHERE form_id = ?', [$formId])->fetch()['c']);

        // Segundo guardado: reemplaza el plan vigente y añade OTRO snapshot al histórico.
        $res = $this->request('PUT', "admin/forms/$formId/sample-plan", [
            'sample_field' => 'age', 'denominator' => 'approved',
            'cells' => [['team_value' => 't2', 'sample_value' => 'a1', 'target' => 25]],
        ], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        // Vigente = solo la celda nueva; histórico = 2 snapshots (no se sobrescribe).
        $rows = DB::run('SELECT team_value, target FROM sample_targets WHERE form_id = ?', [$formId])->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('t2', $rows[0]['team_value']);
        $this->assertSame(2, (int) DB::run('SELECT COUNT(*) c FROM sample_target_history WHERE form_id = ?', [$formId])->fetch()['c']);

        // El panel refleja la config guardada.
        $res = $this->request('GET', "forms/$formId/sample", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertTrue($res['json']['data']['configured']);
        $this->assertSame('age', $res['json']['data']['sample_field']['key']);
        @unlink($jar);
    }

    public function testPanelDenominatorTransientOverride(): void
    {
        $userId = $this->seedUser('admin', 'sp_admin4@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $jar = $this->login('sp_admin4@test.local', 'Secret123!');

        // Plan con denominador 'approved' + un envío APROBADO y otro PENDIENTE.
        $this->request('PUT', "admin/forms/$formId/sample-plan", [
            'sample_field' => 'age', 'denominator' => 'approved',
            'cells' => [['team_value' => 't1', 'sample_value' => 'a1', 'target' => 10]],
        ], $jar);
        foreach ([['u_ok', 'approved'], ['u_pend', 'pending']] as [$uid, $status]) {
            DB::run(
                'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at, review_status, last_synced_at)
                 VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, NOW())',
                [$formId, $uid, json_encode(['team' => 't1', 'age' => 'a1']), $status]
            );
        }

        // Sin parámetro: manda el denominador del plan (solo el aprobado cuenta).
        $res = $this->request('GET', "forms/$formId/sample", null, $jar);
        $this->assertSame('approved', $res['json']['data']['denominator']);
        $this->assertSame(1, $res['json']['data']['grand']['done']);

        // Override transitorio: el pendiente también cuenta, y el efectivo viaja.
        $res = $this->request('GET', "forms/$formId/sample?denominator=approved_pending", null, $jar);
        $this->assertSame('approved_pending', $res['json']['data']['denominator']);
        $this->assertSame(2, $res['json']['data']['grand']['done']);

        // Nada se escribió: el ajuste del plan sigue siendo 'approved'.
        $row = DB::run('SELECT sample_denominator FROM forms WHERE id = ?', [$formId])->fetch();
        $this->assertSame('approved', $row['sample_denominator']);

        // Valor no válido → se ignora (cae al del plan, sin 500 ni 422).
        $res = $this->request('GET', "forms/$formId/sample?denominator=nope", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame('approved', $res['json']['data']['denominator']);
        @unlink($jar);
    }

    public function testPanelNotConfiguredWhenNoSampleField(): void
    {
        $userId = $this->seedUser('admin', 'sp_admin3@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $jar = $this->login('sp_admin3@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/sample", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertFalse($res['json']['data']['configured']);
        @unlink($jar);
    }
}
