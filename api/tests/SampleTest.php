<?php

declare(strict_types=1);

/**
 * Tests de lib/Sample (monitorización de muestra por equipo). Cubren: recuento
 * hecho/objetivo por celda, denominador (approved vs approved_pending), celdas y
 * equipos «fuera de plan», scoping por filas, etiquetas legibles, distribución de
 * campos secundarios y la proyección simple de cierre.
 */
final class SampleTest extends DbTestCase
{
    /** Esquema mono-idioma con equipo (t1/t2), edad de muestreo (a1/a2) y sexo (m/f). */
    private function schema(): array
    {
        return [
            'fields' => [
                'team' => ['leaf' => 'team', 'type' => 'select_one teams', 'list' => 'teams', 'label' => ['' => 'Equipo']],
                'age'  => ['leaf' => 'age',  'type' => 'select_one ages',  'list' => 'ages',  'label' => ['' => 'Edad']],
                'sex'  => ['leaf' => 'sex',  'type' => 'select_one sexes', 'list' => 'sexes', 'label' => ['' => 'Sexo']],
            ],
            'choices' => [
                'teams' => ['t1' => ['' => 'Equipo Uno'], 't2' => ['' => 'Equipo Dos']],
                'ages'  => ['a1' => ['' => '18-29'], 'a2' => ['' => '30-44']],
                'sexes' => ['m' => ['' => 'Hombre'], 'f' => ['' => 'Mujer']],
            ],
            'languages' => [null], 'meta_fields' => [], 'meta' => [],
        ];
    }

    private function addSubmission(int $formId, array $payload, ?string $submittedAt = null): string
    {
        $uid = 'uid_' . bin2hex(random_bytes(6));
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, ?)',
            [$formId, $uid, json_encode($payload, JSON_UNESCAPED_UNICODE), $submittedAt ?? gmdate('Y-m-d H:i:s')]
        );
        return $uid;
    }

    private function review(string $uid, string $status): void
    {
        ValidationStatus::recordReview($uid, $this->makeUser('admin'), 'app', $status);
    }

    private function target(int $formId, string $team, string $value, int $t): void
    {
        DB::run(
            'INSERT INTO sample_targets (form_id, team_value, sample_value, target) VALUES (?, ?, ?, ?)',
            [$formId, $team, $value, $t]
        );
    }

    /** Localiza una fila de equipo por su clave. */
    private function team(array $res, string $key): ?array
    {
        foreach ($res['teams'] as $t) {
            if ($t['key'] === $key) return $t;
        }
        return null;
    }

    private function cell(array $team, string $value): ?array
    {
        foreach ($team['cells'] as $c) {
            if ($c['value'] === $value) return $c;
        }
        return null;
    }

    public function testDoneVsTargetPerCell(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 10);
        $this->target($formId, 't1', 'a2', 5);

        // 3 aprobados en t1/a1, 1 pendiente (no cuenta con denominador 'approved').
        for ($i = 0; $i < 3; $i++) $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');
        $this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']); // pendiente

        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved');

        $t1 = $this->team($res, 't1');
        $this->assertNotNull($t1);
        $cellA1 = $this->cell($t1, 'a1');
        $this->assertSame(3, $cellA1['done']);
        $this->assertSame(10, $cellA1['target']);
        $this->assertSame(30.0, $cellA1['pct']);
        $this->assertFalse($cellA1['out_of_plan']);
        // a2 planificada sin datos: aparece con done 0 (celda con plan no se omite).
        $cellA2 = $this->cell($t1, 'a2');
        $this->assertSame(0, $cellA2['done']);
        $this->assertSame(5, $cellA2['target']);

        $this->assertSame(3, $t1['done']);
        $this->assertSame(15, $t1['target']);
        $this->assertSame(15, $res['grand']['target']);
        $this->assertSame(3, $res['grand']['done']);
        $this->assertTrue($res['has_plan']);
    }

    public function testDenominatorApprovedPendingCountsPending(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 10);
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');
        $this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']); // pendiente
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'rejected'); // nunca cuenta

        $approved = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved');
        $this->assertSame(1, $this->cell($this->team($approved, 't1'), 'a1')['done']);

        $both = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved_pending');
        $this->assertSame(2, $this->cell($this->team($both, 't1'), 'a1')['done']); // aprobado + pendiente, no rechazado
    }

    public function testOutOfPlanCellAndTeam(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 10);
        // Valor a2 en t1 sin objetivo → celda fuera de plan.
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a2']), 'approved');
        // Equipo t2 sin ningún objetivo → fila fuera de plan.
        $this->review($this->addSubmission($formId, ['team' => 't2', 'age' => 'a1']), 'approved');

        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved');

        $t1 = $this->team($res, 't1');
        $this->assertFalse($t1['out_of_plan']);
        $this->assertTrue($this->cell($t1, 'a2')['out_of_plan']);
        $this->assertNull($this->cell($t1, 'a2')['target']);

        $t2 = $this->team($res, 't2');
        $this->assertTrue($t2['out_of_plan']);
    }

    public function testRowScopeRestricts(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 10);
        $this->target($formId, 't2', 'a1', 10);
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');
        $this->review($this->addSubmission($formId, ['team' => 't2', 'age' => 'a1']), 'approved');

        $scope = RowScope::normalize(['conditions' => [['field' => 'team', 'values' => ['t1']]]]);
        $res = Sample::compute($formId, $this->schema(), $scope, null, 'es', 'team', 'age', 'approved');

        // El jefe de t1 solo cuenta lo suyo (t2 sigue como fila planificada pero con 0 hecho).
        $this->assertSame(1, $this->team($res, 't1')['done']);
        $this->assertSame(0, $this->team($res, 't2')['done']);
        $this->assertSame(1, $res['grand']['done']);
    }

    public function testLabelsResolvedNotCodes(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 10);
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');

        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved');

        $this->assertSame('Edad', $res['sample_field']['label']);
        $this->assertSame('Equipo', $res['team_field']['label']);
        $this->assertSame('Equipo Uno', $this->team($res, 't1')['name']);
        $this->assertSame('18-29', $this->cell($this->team($res, 't1'), 'a1')['label']);
    }

    public function testSecondaryDistributionObserved(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 10);
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1', 'sex' => 'm']), 'approved');
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1', 'sex' => 'm']), 'approved');
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1', 'sex' => 'f']), 'approved');

        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved', ['sex']);

        $this->assertCount(1, $res['secondary']);
        $sec = $res['secondary'][0];
        $this->assertSame('Sexo', $sec['label']);
        $this->assertSame(3, $sec['answered']);
        // Ordenado desc: Hombre (2) antes que Mujer (1).
        $this->assertSame('Hombre', $sec['options'][0]['label']);
        $this->assertSame(2, $sec['options'][0]['count']);
    }

    public function testProjectionEtaFromPace(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 20);
        // 10 hechos, primer envío hace 10 días → ritmo 1/día, faltan 10 → ETA ~10 días.
        $first = gmdate('Y-m-d H:i:s', strtotime('2026-01-01 00:00:00 UTC'));
        for ($i = 0; $i < 10; $i++) {
            $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1'], $first), 'approved');
        }

        // «Ahora» fijo = 10 días después del primer envío.
        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved', [], null, '2026-01-11 00:00:00');
        $proj = $this->team($res, 't1')['projection'];
        $this->assertNotNull($proj);
        $this->assertFalse($proj['met']);
        $this->assertSame(10, $proj['remaining']);
        $this->assertEqualsWithDelta(1.0, $proj['rate_per_day'], 0.2);
        // ETA ≈ 10 días tras «ahora» (2026-01-21), con holgura de un par de días por el redondeo.
        $this->assertGreaterThanOrEqual('2026-01-19', $proj['eta']);
        $this->assertLessThanOrEqual('2026-01-23', $proj['eta']);
    }

    public function testProjectionMetWhenReached(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 2);
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');

        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved');
        $proj = $this->team($res, 't1')['projection'];
        $this->assertTrue($proj['met']);
        $this->assertSame(0, $proj['remaining']);
        $this->assertNull($proj['eta']);
    }

    public function testNoPlanStillCountsDone(): void
    {
        $formId = $this->makeForm();
        // Sin objetivos: has_plan false, pero lo hecho se cuenta y todo es «fuera de plan».
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');

        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved');
        $this->assertFalse($res['has_plan']);
        $this->assertSame(1, $res['grand']['done']);
        $this->assertSame(0, $res['grand']['target']);
        $this->assertTrue($this->team($res, 't1')['out_of_plan']);
    }

    public function testReviewBacklogAndCutoff(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 10);

        // t1: 2 aprobados + 1 pendiente + 1 en espera. t2: SOLO 1 pendiente (sin plan
        // ni aprobados: debe aparecer igualmente en el eje, con su backlog).
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');
        $this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']); // pendiente
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a2']), 'on_hold');
        $this->addSubmission($formId, ['team' => 't2', 'age' => 'a1']); // pendiente

        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved');

        // Backlog global y por equipo (por estado ACTUAL, sin importar el denominador).
        $this->assertSame(2, $res['grand']['pending']);
        $this->assertSame(1, $res['grand']['on_hold']);
        $t1 = $this->team($res, 't1');
        $this->assertSame(1, $t1['pending']);
        $this->assertSame(1, $t1['on_hold']);
        $t2 = $this->team($res, 't2');
        $this->assertNotNull($t2, 'un equipo con solo backlog aparece en el eje');
        $this->assertSame(0, $t2['done']);
        $this->assertSame(1, $t2['pending']);

        // Corte: última acción de APROBAR registrada en submission_reviews.
        $expected = DB::run(
            "SELECT MAX(created_at) AS m FROM submission_reviews WHERE status = 'approved'"
        )->fetch()['m'];
        $this->assertNotNull($res['last_approved_at']);
        $this->assertSame($expected, $res['last_approved_at']);

        // Con denominador approved_pending el pendiente cuenta como hecho Y como backlog.
        $res2 = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved_pending');
        $this->assertSame(3, $this->team($res2, 't1')['done']); // 2 aprobados + 1 pendiente
        $this->assertSame(1, $this->team($res2, 't1')['pending']);
    }

    public function testCutoffNullWithoutApprovals(): void
    {
        $formId = $this->makeForm();
        $this->target($formId, 't1', 'a1', 5);
        $this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']); // pendiente

        $res = Sample::compute($formId, $this->schema(), null, null, 'es', 'team', 'age', 'approved');
        $this->assertNull($res['last_approved_at']);
        $this->assertSame(1, $res['grand']['pending']);
        $this->assertSame(0, $res['grand']['on_hold']);
    }

    public function testCutoffRespectsRowScope(): void
    {
        $formId = $this->makeForm();
        // Aprobado en t1 y en t2; con alcance restringido a t1, el corte y el backlog
        // solo ven t1 (mismo alcance que el resto del panel).
        $this->review($this->addSubmission($formId, ['team' => 't1', 'age' => 'a1']), 'approved');
        $this->review($this->addSubmission($formId, ['team' => 't2', 'age' => 'a1']), 'approved');
        $this->addSubmission($formId, ['team' => 't2', 'age' => 'a1']); // pendiente fuera del alcance

        $scope = RowScope::normalize(['conditions' => [['field' => 'team', 'values' => ['t1']]]]);
        $res = Sample::compute($formId, $this->schema(), $scope, null, 'es', 'team', 'age', 'approved');

        $this->assertSame(1, $res['grand']['done']);
        $this->assertSame(0, $res['grand']['pending']);
        $expected = DB::run(
            "SELECT MAX(r.created_at) AS m FROM submission_reviews r
             JOIN submissions_cache sc ON sc.submission_uid = r.submission_uid
             WHERE r.status = 'approved' AND JSON_UNQUOTE(JSON_EXTRACT(sc.json_payload, '$.team')) = 't1'"
        )->fetch()['m'];
        $this->assertSame($expected, $res['last_approved_at']);
    }
}
