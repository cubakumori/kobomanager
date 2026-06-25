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

    // ---- Filtro por estado de revisión (tarjetas del encabezado) ----

    public function testFilterApprovedRestrictsMetricsButNotHeader(): void
    {
        $formId = $this->makeForm();
        $u1 = $this->addSubmission($formId, ['_id' => 1, 'region' => 'norte']);
        $this->addSubmission($formId, ['_id' => 2, 'region' => 'sur']);
        $this->addSubmission($formId, ['_id' => 3, 'region' => 'este']);
        $this->review($u1, 'approved');

        $stats = Stats::compute($formId, null, null, null, 'es', true, null, null, 'approved');

        // El encabezado refleja SIEMPRE el conjunto completo.
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['by_status']['approved']);
        $this->assertSame(2, $stats['by_status']['pending']);
        // Las métricas mostradas se restringen al subconjunto aprobado.
        $this->assertSame('approved', $stats['filter']);
        $this->assertSame(1, $stats['base']);
        $this->assertSame(1, array_sum(array_column($stats['by_day'], 'count')));
        $this->assertSame(1, (int) $stats['trend']['last_7']);
    }

    public function testFilterPendingIncludesUnreviewed(): void
    {
        $formId = $this->makeForm();
        $u1 = $this->addSubmission($formId, ['_id' => 1]);
        $this->addSubmission($formId, ['_id' => 2]); // sin revisión → pendiente
        $u3 = $this->addSubmission($formId, ['_id' => 3]);
        $this->review($u1, 'approved');
        $this->review($u3, 'pending'); // revisión explícita 'pending'

        $stats = Stats::compute($formId, null, null, null, 'es', true, null, null, 'pending');
        // pendiente = sin revisión + última revisión 'pending' = 2; excluye el aprobado.
        $this->assertSame(2, $stats['base']);
        $this->assertSame(3, $stats['total']);
    }

    public function testFilterAppliesEvenWithoutReviewBlock(): void
    {
        // El filtro por estado es INDEPENDIENTE de includeReview: una vista pública
        // (sin el bloque by_status) puede acotarse a «solo aprobados». `by_status`
        // sigue omitido, pero el conjunto se restringe igualmente.
        $formId = $this->makeForm();
        $u1 = $this->addSubmission($formId, ['_id' => 1]);
        $this->addSubmission($formId, ['_id' => 2]);
        $this->review($u1, 'approved');

        $stats = Stats::compute($formId, null, null, null, 'es', false, null, null, 'approved');
        $this->assertSame('approved', $stats['filter']);
        $this->assertSame(1, $stats['base']);
        $this->assertArrayNotHasKey('by_status', $stats); // sigue sin exponerse
    }

    public function testExtraScopeAndedWithScope(): void
    {
        // `$extraScope` (alcance fijo, p. ej. equipos de un enlace) se combina en AND y
        // restringe TODO, incluido el desglose por equipo.
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A']);
        $this->addSubmission($formId, ['_id' => 2, 'team' => 'A']);
        $this->addSubmission($formId, ['_id' => 3, 'team' => 'B']);

        $extra = RowScope::teamRule('team', ['A']);
        $stats = Stats::compute($formId, null, null, null, 'es', true, 'team', null, null, null, $extra);
        $this->assertSame(2, $stats['base']);
        $this->assertSame(2, $stats['total']); // total respeta el alcance fijo (scope+extra)
        // Solo el equipo A entra en el desglose (alcance fijo, no toggles).
        $this->assertCount(1, $stats['by_team']);
        $this->assertSame('A', $stats['by_team'][0]['key']);
    }

    // ---- Filtro por equipos (checkboxes del desglose) ----

    public function testTeamSelectionRestrictsAggregatesButKeepsBars(): void
    {
        $formId = $this->makeForm();
        // Equipo A: 3 ; Equipo B: 1
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A', '_submitted_by' => 'ana']);
        $this->addSubmission($formId, ['_id' => 2, 'team' => 'A', '_submitted_by' => 'ana']);
        $this->addSubmission($formId, ['_id' => 3, 'team' => 'A', '_submitted_by' => 'beto']);
        $this->addSubmission($formId, ['_id' => 4, 'team' => 'B', '_submitted_by' => 'cris']);

        // Solo el equipo A seleccionado.
        $stats = Stats::compute($formId, null, null, null, 'es', true, 'team', null, null, ['A']);

        // El total del encabezado no cambia; las métricas agregadas sí (base = 3 de A).
        $this->assertSame(4, $stats['total']);
        $this->assertSame(3, $stats['base']);
        // Las barras por equipo se mantienen COMPLETAS (A y B) con sus claves.
        $this->assertCount(2, $stats['by_team']);
        $keys = array_column($stats['by_team'], 'key');
        sort($keys);
        $this->assertSame(['A', 'B'], $keys);
        // La cuota de cada equipo es estable (sobre los 4, no sobre la selección).
        $a = array_values(array_filter($stats['by_team'], fn($t) => $t['key'] === 'A'))[0];
        $this->assertSame(3, $a['count']);
        $this->assertSame(75.0, $a['pct']); // 3/4, no 3/3
        // Eco de la selección activa.
        $this->assertSame(['A'], $stats['team_selection']);
    }

    public function testTeamSelectionEmptyMatchesNothing(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A']);
        $this->addSubmission($formId, ['_id' => 2, 'team' => 'B']);

        // Todos los equipos desmarcados → base 0, pero las barras siguen completas.
        $stats = Stats::compute($formId, null, null, null, 'es', true, 'team', null, null, []);
        $this->assertSame(0, $stats['base']);
        $this->assertSame(2, $stats['total']);
        $this->assertCount(2, $stats['by_team']);
    }

    public function testTeamSelectionNoneBucket(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A']);
        $this->addSubmission($formId, ['_id' => 2]); // sin equipo → bucket '__none__'

        // Seleccionar solo «sin equipo».
        $stats = Stats::compute($formId, null, null, null, 'es', true, 'team', null, null, ['__none__']);
        $this->assertSame(1, $stats['base']);
        $keys = array_column($stats['by_team'], 'key');
        $this->assertContains('__none__', $keys);
    }

    public function testTeamSelectionIgnoredWithoutTeamField(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['_id' => 1, 'team' => 'A']);
        $this->addSubmission($formId, ['_id' => 2, 'team' => 'B']);

        // Sin campo de equipo configurado, la selección se ignora (base = total).
        $stats = Stats::compute($formId, null, null, null, 'es', true, null, null, null, ['A']);
        $this->assertSame(2, $stats['base']);
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
