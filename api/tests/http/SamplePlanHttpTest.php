<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del plan de muestra (admin/forms/{id}/sample-plan) y del panel
 * (forms/{id}/sample). Cubre: permiso «Ajustes», el guard de que el campo de muestreo
 * sea de opción única (select_one, no select_multiple ni inexistente), el reemplazo
 * del plan vigente + snapshot en el histórico, y que el panel exija can_view.
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
