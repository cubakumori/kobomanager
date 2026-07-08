<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: índice de riesgo (GET /forms/{id}/risk). Opt-in vía
 * `forms.risk_min_n`; requiere permiso de vista; respeta el desglose por encuestador.
 */
final class RiskHttpTest extends HttpTestCase
{
    private function enableRisk(int $formId, int $minN): void
    {
        DB::run('UPDATE forms SET risk_min_n = ?, stats_enumerator_field = ? WHERE id = ?',
            [$minN, 'enum', $formId]);
    }

    public function testDisabledWhenNotConfigured(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'a1', ['enum' => 'ana', 'p1' => 'x', 'p2' => 'y']);
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/risk", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['json']['data']['enabled']);
        $this->assertSame([], $res['json']['data']['teams']);
        @unlink($jar);
    }

    public function testScoresFabricator(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->enableRisk($formId, 3);
        // fab: 3 idénticos → percentmatch 1.0. Tres pares honestos con respuestas distintas.
        foreach ([1, 2, 3] as $i) $this->seedSubmission($formId, "fab$i", ['enum' => 'fab', 'p1' => 'a', 'p2' => 'b']);
        foreach ([1, 2, 3] as $i) $this->seedSubmission($formId, "ann$i", ['enum' => 'ann', 'p1' => "a$i", 'p2' => "b$i"]);
        foreach ([1, 2, 3] as $i) $this->seedSubmission($formId, "ben$i", ['enum' => 'ben', 'p1' => "c$i", 'p2' => "d$i"]);
        $this->seedSubmission($formId, 'cid1', ['enum' => 'cid', 'p1' => 'x', 'p2' => 'y']);
        $this->seedSubmission($formId, 'cid2', ['enum' => 'cid', 'p1' => 'x', 'p2' => 'z']);
        $this->seedSubmission($formId, 'cid3', ['enum' => 'cid', 'p1' => 'q', 'p2' => 'w']);
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/risk", null, $jar);
        $this->assertSame(200, $res['status']);
        $d = $res['json']['data'];
        $this->assertTrue($d['enabled']);
        $this->assertSame(3, $d['min_n']);
        $this->assertSame(4, $d['scored']);
        $top = $d['teams'][0]['enumerators'][0];
        $this->assertSame('fab', $top['name']);
        $this->assertGreaterThanOrEqual($d['suspicion_z'], $top['index']);
        @unlink($jar);
    }

    public function testForbiddenWithoutView(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->enableRisk($formId, 3);
        // sin grant → sin can_view sobre el formulario
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/risk", null, $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }
}
