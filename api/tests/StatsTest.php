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
}
