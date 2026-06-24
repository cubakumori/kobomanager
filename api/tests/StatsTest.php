<?php

declare(strict_types=1);

/**
 * Tests de lib/Stats (cálculo compartido por forms/stats.php y el endpoint
 * público de enlaces). El punto crítico: el bloque `by_status` (estado de
 * revisión interno) solo se incluye cuando se pide explícitamente, de modo que
 * los enlaces públicos no lo expongan.
 */
final class StatsTest extends DbTestCase
{
    private function addSubmission(int $formId, array $payload): string
    {
        $uid = 'uid_' . bin2hex(random_bytes(6));
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, NOW())',
            [$formId, $uid, json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
        return $uid;
    }

    private function review(string $uid, string $status): void
    {
        DB::run(
            'INSERT INTO submission_reviews (submission_uid, user_id, status) VALUES (?, ?, ?)',
            [$uid, $this->makeUser('admin'), $status]
        );
    }

    public function testByStatusOnlyIncludedWhenReviewRequested(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'region' => 'norte']);
        $this->addSubmission($formId, ['_id' => 2, 'region' => 'sur']);

        $withReview = Stats::compute($formId, null, null, null, 'es', true);
        $this->assertArrayHasKey('by_status', $withReview);
        $this->assertSame(2, $withReview['by_status']['pending']); // sin revisión → pending

        $public = Stats::compute($formId, null, null, null, 'es', false);
        $this->assertArrayNotHasKey('by_status', $public);
        // El resto de métricas siguen presentes en ambos.
        $this->assertSame(2, $public['total']);
        $this->assertArrayHasKey('by_day', $public);
        $this->assertArrayHasKey('attachments', $public);
    }

    public function testRowScopeRestrictsTotal(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'region' => 'norte']);
        $this->addSubmission($formId, ['_id' => 2, 'region' => 'sur']);

        $scope = RowScope::normalize(['conditions' => [['field' => 'region', 'values' => ['norte']]]]);
        $stats = Stats::compute($formId, null, $scope, null, 'es', false);
        $this->assertSame(1, $stats['total']);
    }

    // ---- Desglose por equipo → encuestador ----

    public function testByTeamAbsentWhenUnconfigured(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A', '_submitted_by' => 'ana']);

        $stats = Stats::compute($formId, null, null, null, 'es', true); // sin teamField
        $this->assertArrayNotHasKey('by_team', $stats);
    }

    public function testByTeamVolumeAndNesting(): void
    {
        $formId = $this->makeForm();
        // Equipo A: ana×2, beto×1 ; Equipo B: cris×1
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A', '_submitted_by' => 'ana']);
        $this->addSubmission($formId, ['_id' => 2, 'team' => 'A', '_submitted_by' => 'ana']);
        $this->addSubmission($formId, ['_id' => 3, 'team' => 'A', '_submitted_by' => 'beto']);
        $this->addSubmission($formId, ['_id' => 4, 'team' => 'B', '_submitted_by' => 'cris']);

        $stats = Stats::compute($formId, null, null, null, 'es', true, 'team', null);
        $this->assertArrayHasKey('by_team', $stats);
        $this->assertSame('team', $stats['team_field']['key']);
        $this->assertNull($stats['enumerator_field']['label']); // _submitted_by → genérico

        $teams = $stats['by_team'];
        $this->assertCount(2, $teams);
        // Orden desc por volumen: A (3) antes que B (1).
        $this->assertSame('A', $teams[0]['name']);
        $this->assertSame(3, $teams[0]['count']);
        $this->assertSame(75.0, $teams[0]['pct']); // 3/4 sobre el total
        $this->assertSame('B', $teams[1]['name']);
        $this->assertSame(1, $teams[1]['count']);

        // Encuestadores dentro de A: ana (2) antes que beto (1); % sobre el equipo (3).
        $enumsA = $teams[0]['enumerators'];
        $this->assertSame('ana', $enumsA[0]['name']);
        $this->assertSame(2, $enumsA[0]['count']);
        $this->assertEqualsWithDelta(66.7, $enumsA[0]['pct'], 0.1);
        $this->assertSame('beto', $enumsA[1]['name']);
    }

    public function testByTeamReviewMixInternalOnly(): void
    {
        $formId = $this->makeForm();
        $u1 = $this->addSubmission($formId, ['_id' => 1, 'team' => 'A', '_submitted_by' => 'ana']);
        $this->addSubmission($formId, ['_id' => 2, 'team' => 'A', '_submitted_by' => 'ana']);
        $this->review($u1, 'approved');

        $internal = Stats::compute($formId, null, null, null, 'es', true, 'team', null);
        $teamA = $internal['by_team'][0];
        $this->assertArrayHasKey('status', $teamA);
        $this->assertSame(1, $teamA['status']['approved']);
        $this->assertSame(1, $teamA['status']['pending']); // el no revisado
        $this->assertArrayHasKey('status', $teamA['enumerators'][0]);

        // Público: by_team presente (volumen) pero SIN mezcla de revisión.
        $public = Stats::compute($formId, null, null, null, 'es', false, 'team', null);
        $this->assertArrayHasKey('by_team', $public);
        $this->assertArrayNotHasKey('status', $public['by_team'][0]);
        $this->assertSame(2, $public['by_team'][0]['count']);
    }

    public function testByTeamOmittedWhenTeamFieldHidden(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A', '_submitted_by' => 'ana']);

        // El campo de equipo está oculto a este alcance → no se puede agrupar por él.
        $fieldScope = FieldScope::normalize(['hidden' => ['team']]);
        $stats = Stats::compute($formId, null, null, $fieldScope, 'es', true, 'team', null);
        $this->assertArrayNotHasKey('by_team', $stats);
    }

    public function testByTeamEnumeratorFieldOverride(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A', 'worker' => 'w1', '_submitted_by' => 'ana']);
        $this->addSubmission($formId, ['_id' => 2, 'team' => 'A', 'worker' => 'w2', '_submitted_by' => 'ana']);

        $stats = Stats::compute($formId, null, null, null, 'es', true, 'team', 'worker');
        $this->assertSame('worker', $stats['enumerator_field']['key']);
        $names = array_column($stats['by_team'][0]['enumerators'], 'name');
        sort($names);
        $this->assertSame(['w1', 'w2'], $names); // agrupa por `worker`, no por `_submitted_by`
    }
}
