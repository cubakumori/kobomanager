<?php

declare(strict_types=1);

/**
 * Tests del scoping por filas (lib/RowScope): normalización, regla por usuario,
 * coincidencia en PHP y condición SQL contra la BD de test.
 */
final class RowScopeTest extends DbTestCase
{
    /** Inserta un envío en caché y devuelve su submission_uid. */
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

    private function setPermission(int $userId, int $formId, ?array $filter): void
    {
        DB::run(
            'INSERT INTO user_form_permissions (user_id, form_id, can_view, row_filter)
             VALUES (?, ?, 1, ?)',
            [$userId, $formId, $filter !== null ? json_encode($filter) : null]
        );
    }

    // ---------- normalize ----------

    public function testNormalizeProducesCanonicalShape(): void
    {
        $rule = RowScope::normalize([
            'conditions' => [
                ['field' => 'region', 'values' => ['norte', 'sur', 'norte']], // duplicado
                ['field' => '  ', 'values' => ['x']],                          // campo vacío → fuera
                ['field' => 'edad', 'values' => [1, 2]],                       // numéricos → string
            ],
        ]);
        $this->assertSame(
            ['conditions' => [
                ['field' => 'region', 'values' => ['norte', 'sur']],
                ['field' => 'edad', 'values' => ['1', '2']],
            ]],
            $rule
        );
    }

    public function testNormalizeEmptyOrInvalidReturnsNull(): void
    {
        $this->assertNull(RowScope::normalize(null));
        $this->assertNull(RowScope::normalize('nope'));
        $this->assertNull(RowScope::normalize([]));
        $this->assertNull(RowScope::normalize(['conditions' => []]));
        $this->assertNull(RowScope::normalize(['conditions' => [['field' => '']]]));
    }

    public function testNormalizeKeepsConditionWithEmptyValues(): void
    {
        // Una condición con campo pero sin valores se conserva (significa fail-closed).
        $rule = RowScope::normalize(['conditions' => [['field' => 'region', 'values' => []]]]);
        $this->assertSame(['conditions' => [['field' => 'region', 'values' => []]]], $rule);
    }

    // ---------- ruleForUser ----------

    public function testAdminHasNoRestriction(): void
    {
        $formId = $this->makeForm();
        $admin  = ['id' => 1, 'role' => 'admin'];
        $this->assertNull(RowScope::ruleForUser($admin, $formId));
    }

    public function testViewerWithoutFilterReturnsNull(): void
    {
        $formId = $this->makeForm();
        $userId = $this->makeUser('viewer');
        $this->setPermission($userId, $formId, null);
        $this->assertNull(RowScope::ruleForUser(['id' => $userId, 'role' => 'viewer'], $formId));
    }

    public function testViewerWithFilterReturnsNormalizedRule(): void
    {
        $formId = $this->makeForm();
        $userId = $this->makeUser('viewer');
        $this->setPermission($userId, $formId, ['conditions' => [['field' => 'region', 'values' => ['norte']]]]);
        $rule = RowScope::ruleForUser(['id' => $userId, 'role' => 'viewer'], $formId);
        $this->assertSame(['conditions' => [['field' => 'region', 'values' => ['norte']]]], $rule);
    }

    // ---------- matches ----------

    public function testMatchesNullRuleAlwaysTrue(): void
    {
        $this->assertTrue(RowScope::matches(null, ['region' => 'norte']));
    }

    public function testMatchesSingleCondition(): void
    {
        $rule = RowScope::normalize(['conditions' => [['field' => 'region', 'values' => ['norte', 'sur']]]]);
        $this->assertTrue(RowScope::matches($rule, ['region' => 'norte']));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'este']));
        $this->assertFalse(RowScope::matches($rule, ['otra' => 'norte'])); // campo ausente
    }

    public function testMatchesAndAcrossConditions(): void
    {
        $rule = RowScope::normalize(['conditions' => [
            ['field' => 'region', 'values' => ['norte']],
            ['field' => 'g_a/equipo', 'values' => ['1', '2']],
        ]]);
        $this->assertTrue(RowScope::matches($rule, ['region' => 'norte', 'g_a/equipo' => '2']));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'norte', 'g_a/equipo' => '3']));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'sur', 'g_a/equipo' => '1']));
    }

    public function testMatchesCastsNumbersToString(): void
    {
        $rule = RowScope::normalize(['conditions' => [['field' => 'edad', 'values' => ['18']]]]);
        $this->assertTrue(RowScope::matches($rule, ['edad' => 18]));   // int en el payload
    }

    public function testMatchesFailClosedOnEmptyValues(): void
    {
        $rule = ['conditions' => [['field' => 'region', 'values' => []]]];
        $this->assertFalse(RowScope::matches($rule, ['region' => 'norte']));
    }

    public function testMatchesNonScalarIsFalse(): void
    {
        $rule = RowScope::normalize(['conditions' => [['field' => 'sel', 'values' => ['a']]]]);
        $this->assertFalse(RowScope::matches($rule, ['sel' => ['a', 'b']])); // select_multiple → array
        $this->assertFalse(RowScope::matches($rule, ['sel' => null]));
    }

    public function testMatchesSubmittedBy(): void
    {
        $rule = RowScope::normalize(['conditions' => [['field' => '_submitted_by', 'values' => ['alice', 'bob']]]]);
        $this->assertTrue(RowScope::matches($rule, ['_submitted_by' => 'alice']));
        $this->assertFalse(RowScope::matches($rule, ['_submitted_by' => 'carol']));
    }

    // ---------- sqlCondition ----------

    public function testSqlConditionNullRuleIsNoop(): void
    {
        $this->assertSame(['1=1', []], RowScope::sqlCondition(null, 'json_payload'));
    }

    public function testSqlConditionFailClosed(): void
    {
        $rule = ['conditions' => [['field' => 'region', 'values' => []]]];
        $this->assertSame(['1=0', []], RowScope::sqlCondition($rule, 'json_payload'));
    }

    public function testSqlConditionFiltersRealRows(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['region' => 'norte', 'g_a/equipo' => '1']);
        $this->addSubmission($formId, ['region' => 'norte', 'g_a/equipo' => '2']);
        $this->addSubmission($formId, ['region' => 'sur',   'g_a/equipo' => '1']);
        $this->addSubmission($formId, ['region' => 'este']); // sin g_a/equipo

        // region IN ('norte') → 2 filas
        $rule = RowScope::normalize(['conditions' => [['field' => 'region', 'values' => ['norte']]]]);
        $this->assertSame(2, $this->scopedCount($formId, $rule));

        // region IN ('norte') AND g_a/equipo IN ('2') → 1 fila (ruta de grupo)
        $rule2 = RowScope::normalize(['conditions' => [
            ['field' => 'region', 'values' => ['norte']],
            ['field' => 'g_a/equipo', 'values' => ['2']],
        ]]);
        $this->assertSame(1, $this->scopedCount($formId, $rule2));

        // sin filtro → todas
        $this->assertSame(4, $this->scopedCount($formId, null));

        // fail-closed → 0
        $this->assertSame(0, $this->scopedCount($formId, ['conditions' => [['field' => 'region', 'values' => []]]]));
    }

    private function scopedCount(int $formId, ?array $rule): int
    {
        [$sql, $params] = RowScope::sqlCondition($rule, 'json_payload');
        return (int) DB::run(
            "SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ? AND $sql",
            array_merge([$formId], $params)
        )->fetch()['c'];
    }
}
