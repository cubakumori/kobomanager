<?php

declare(strict_types=1);

/**
 * Tests del scoping por filas (lib/RowScope): normalización + retrocompat,
 * grupos AND/OR a 2 niveles, todos los operadores, select_multiple, fail-closed,
 * y una batería de PARIDAD SQL≡PHP (matches() y sqlCondition() deben coincidir
 * fila a fila contra la BD de test).
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

    /** Forma canónica corta para un solo grupo AND con una condición. */
    private static function rule(string $field, string $op, array $values = []): array
    {
        return ['match' => 'all', 'groups' => [
            ['match' => 'all', 'conditions' => [['field' => $field, 'op' => $op, 'values' => $values]]],
        ]];
    }

    // ---------- normalize: retrocompat ----------

    public function testNormalizeOldFormatWrapsInSingleAndGroup(): void
    {
        $rule = RowScope::normalize([
            'conditions' => [
                ['field' => 'region', 'values' => ['norte', 'sur', 'norte']], // duplicado
                ['field' => '  ', 'values' => ['x']],                          // campo vacío → fuera
                ['field' => 'edad', 'values' => [1, 2]],                       // numéricos → string
            ],
        ]);
        $this->assertSame(['match' => 'all', 'groups' => [
            ['match' => 'all', 'conditions' => [
                ['field' => 'region', 'op' => 'in', 'values' => ['norte', 'sur']],
                ['field' => 'edad',   'op' => 'in', 'values' => ['1', '2']],
            ]],
        ]], $rule);
    }

    public function testNormalizeEmptyOrInvalidReturnsNull(): void
    {
        $this->assertNull(RowScope::normalize(null));
        $this->assertNull(RowScope::normalize('nope'));
        $this->assertNull(RowScope::normalize([]));
        $this->assertNull(RowScope::normalize(['conditions' => []]));
        $this->assertNull(RowScope::normalize(['conditions' => [['field' => '']]]));
        $this->assertNull(RowScope::normalize(['groups' => []]));
        $this->assertNull(RowScope::normalize(['groups' => [['conditions' => []]]]));
    }

    // ---------- normalize: forma nueva ----------

    public function testNormalizeNewFormatKeepsMatchAndOps(): void
    {
        $rule = RowScope::normalize([
            'match' => 'any',
            'groups' => [
                ['match' => 'all', 'conditions' => [
                    ['field' => 'region', 'op' => 'in', 'values' => ['norte']],
                    ['field' => 'edad', 'op' => 'gte', 'values' => ['18']],
                ]],
                ['match' => 'all', 'conditions' => [
                    ['field' => 'region', 'op' => 'in', 'values' => ['sur']],
                ]],
            ],
        ]);
        $this->assertSame('any', $rule['match']);
        $this->assertCount(2, $rule['groups']);
        $this->assertSame('gte', $rule['groups'][0]['conditions'][1]['op']);
    }

    public function testNormalizeDefaultsMatchToAllAndOpToIn(): void
    {
        $rule = RowScope::normalize([
            'groups' => [['conditions' => [['field' => 'region', 'values' => ['norte']]]]],
        ]);
        $this->assertSame('all', $rule['match']);
        $this->assertSame('all', $rule['groups'][0]['match']);
        $this->assertSame('in', $rule['groups'][0]['conditions'][0]['op']);
    }

    public function testNormalizeUnknownOpBecomesIn(): void
    {
        $rule = RowScope::normalize(self::rule('region', 'wat', ['norte']));
        $this->assertSame('in', $rule['groups'][0]['conditions'][0]['op']);
    }

    public function testNormalizeEmptyValuesPerOp(): void
    {
        // 'in' sin valores se conserva (sentinela fail-closed)…
        $in = RowScope::normalize(self::rule('region', 'in', []));
        $this->assertSame([['field' => 'region', 'op' => 'in', 'values' => []]],
            $in['groups'][0]['conditions']);

        // …pero nin / has_* / rango sin valores se descartan (no-op) → grupo vacío → null.
        $this->assertNull(RowScope::normalize(self::rule('region', 'nin', [])));
        $this->assertNull(RowScope::normalize(self::rule('sel', 'has_any', [])));
        $this->assertNull(RowScope::normalize(self::rule('edad', 'gte', [''])));
    }

    public function testNormalizeRangeKeepsSingleOperand(): void
    {
        $rule = RowScope::normalize(self::rule('edad', 'lt', ['5', '9']));
        $this->assertSame(['5'], $rule['groups'][0]['conditions'][0]['values']);
    }

    public function testNormalizeEmptyOpHasNoValues(): void
    {
        $rule = RowScope::normalize(self::rule('nota', 'empty', ['ignorado']));
        $this->assertSame([], $rule['groups'][0]['conditions'][0]['values']);
        $this->assertSame('empty', $rule['groups'][0]['conditions'][0]['op']);
    }

    // ---------- ruleForUser ----------

    public function testAdminHasNoRestriction(): void
    {
        $formId = $this->makeForm();
        $this->assertNull(RowScope::ruleForUser(['id' => 1, 'role' => 'admin'], $formId));
    }

    public function testViewerWithoutFilterReturnsNull(): void
    {
        $formId = $this->makeForm();
        $userId = $this->makeUser('viewer');
        $this->setPermission($userId, $formId, null);
        $this->assertNull(RowScope::ruleForUser(['id' => $userId, 'role' => 'viewer'], $formId));
    }

    public function testViewerWithOldFilterIsUpgraded(): void
    {
        $formId = $this->makeForm();
        $userId = $this->makeUser('viewer');
        // Guardado en el formato ANTIGUO: debe leerse y canonicalizarse.
        $this->setPermission($userId, $formId, ['conditions' => [['field' => 'region', 'values' => ['norte']]]]);
        $rule = RowScope::ruleForUser(['id' => $userId, 'role' => 'viewer'], $formId);
        $this->assertSame('in', $rule['groups'][0]['conditions'][0]['op']);
        $this->assertSame(['norte'], $rule['groups'][0]['conditions'][0]['values']);
    }

    // ---------- matches: operadores ----------

    public function testMatchesNullRuleAlwaysTrue(): void
    {
        $this->assertTrue(RowScope::matches(null, ['region' => 'norte']));
    }

    public function testMatchesIn(): void
    {
        $rule = RowScope::normalize(self::rule('region', 'in', ['norte', 'sur']));
        $this->assertTrue(RowScope::matches($rule, ['region' => 'norte']));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'este']));
        $this->assertFalse(RowScope::matches($rule, ['otra' => 'norte'])); // ausente
    }

    public function testMatchesNinIncludesMissing(): void
    {
        $rule = RowScope::normalize(self::rule('region', 'nin', ['norte']));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'norte']));
        $this->assertTrue($this->bool(RowScope::matches($rule, ['region' => 'sur'])));
        $this->assertTrue(RowScope::matches($rule, ['otra' => 'x'])); // ausente → incluido
    }

    public function testMatchesNumericRange(): void
    {
        $rule = RowScope::normalize(self::rule('edad', 'gte', ['18']));
        $this->assertTrue(RowScope::matches($rule, ['edad' => 18]));   // int
        $this->assertTrue(RowScope::matches($rule, ['edad' => '20'])); // string numérico
        $this->assertFalse(RowScope::matches($rule, ['edad' => 17]));
        $this->assertFalse(RowScope::matches($rule, ['edad' => 'abc'])); // no numérico → false
        // comparación numérica, no lexical: '9' < '18' aunque "9" > "18" como texto
        $lt = RowScope::normalize(self::rule('edad', 'lt', ['18']));
        $this->assertTrue(RowScope::matches($lt, ['edad' => '9']));
    }

    public function testMatchesDateLexicalRange(): void
    {
        $rule = RowScope::normalize(self::rule('fecha', 'gte', ['2024-01-01']));
        $this->assertTrue(RowScope::matches($rule, ['fecha' => '2024-06-08']));
        $this->assertFalse(RowScope::matches($rule, ['fecha' => '2023-12-31']));
    }

    public function testMatchesEmptyNotEmpty(): void
    {
        $empty = RowScope::normalize(self::rule('nota', 'empty'));
        $this->assertTrue(RowScope::matches($empty, ['nota' => '']));
        $this->assertTrue(RowScope::matches($empty, ['otra' => 'x']));   // ausente → vacío
        $this->assertFalse(RowScope::matches($empty, ['nota' => 'hola']));

        $notEmpty = RowScope::normalize(self::rule('nota', 'not_empty'));
        $this->assertTrue(RowScope::matches($notEmpty, ['nota' => 'hola']));
        $this->assertFalse(RowScope::matches($notEmpty, ['nota' => '']));
    }

    public function testMatchesSelectMultiple(): void
    {
        $payload = ['sel' => 'a c d']; // códigos separados por espacios
        $this->assertTrue(RowScope::matches(RowScope::normalize(self::rule('sel', 'has_any', ['c', 'z'])), $payload));
        $this->assertFalse(RowScope::matches(RowScope::normalize(self::rule('sel', 'has_any', ['x', 'z'])), $payload));
        $this->assertTrue(RowScope::matches(RowScope::normalize(self::rule('sel', 'has_all', ['a', 'c'])), $payload));
        $this->assertFalse(RowScope::matches(RowScope::normalize(self::rule('sel', 'has_all', ['a', 'z'])), $payload));
        $this->assertTrue(RowScope::matches(RowScope::normalize(self::rule('sel', 'has_none', ['x', 'y'])), $payload));
        $this->assertFalse(RowScope::matches(RowScope::normalize(self::rule('sel', 'has_none', ['a'])), $payload));
        // has_none sobre campo ausente → no contiene ninguno → true
        $this->assertTrue(RowScope::matches(RowScope::normalize(self::rule('sel', 'has_none', ['a'])), ['otra' => 1]));
    }

    public function testMatchesFailClosedOnEmptyIn(): void
    {
        $rule = ['match' => 'all', 'groups' => [['match' => 'all', 'conditions' => [['field' => 'region', 'op' => 'in', 'values' => []]]]]];
        $this->assertFalse(RowScope::matches($rule, ['region' => 'norte']));
    }

    // ---------- matches: conectores AND/OR ----------

    public function testMatchesAndWithinGroup(): void
    {
        $rule = RowScope::normalize([
            'groups' => [['match' => 'all', 'conditions' => [
                ['field' => 'region', 'op' => 'in', 'values' => ['norte']],
                ['field' => 'edad', 'op' => 'gte', 'values' => ['18']],
            ]]],
        ]);
        $this->assertTrue(RowScope::matches($rule, ['region' => 'norte', 'edad' => 20]));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'norte', 'edad' => 10]));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'sur', 'edad' => 20]));
    }

    public function testMatchesOrWithinGroup(): void
    {
        $rule = RowScope::normalize([
            'groups' => [['match' => 'any', 'conditions' => [
                ['field' => 'region', 'op' => 'in', 'values' => ['norte']],
                ['field' => 'region', 'op' => 'in', 'values' => ['sur']],
            ]]],
        ]);
        $this->assertTrue(RowScope::matches($rule, ['region' => 'sur']));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'este']));
    }

    public function testMatchesDnfRootAnyOverGroups(): void
    {
        // (region=norte Y equipo=1) O (region=sur Y equipo=2)
        $rule = RowScope::normalize([
            'match' => 'any',
            'groups' => [
                ['match' => 'all', 'conditions' => [
                    ['field' => 'region', 'op' => 'in', 'values' => ['norte']],
                    ['field' => 'equipo', 'op' => 'in', 'values' => ['1']],
                ]],
                ['match' => 'all', 'conditions' => [
                    ['field' => 'region', 'op' => 'in', 'values' => ['sur']],
                    ['field' => 'equipo', 'op' => 'in', 'values' => ['2']],
                ]],
            ],
        ]);
        $this->assertTrue(RowScope::matches($rule, ['region' => 'norte', 'equipo' => '1']));
        $this->assertTrue(RowScope::matches($rule, ['region' => 'sur', 'equipo' => '2']));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'norte', 'equipo' => '2']));
        $this->assertFalse(RowScope::matches($rule, ['region' => 'sur', 'equipo' => '1']));
    }

    public function testMatchesSubmittedBy(): void
    {
        $rule = RowScope::normalize(self::rule('_submitted_by', 'in', ['alice', 'bob']));
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
        $rule = RowScope::normalize(self::rule('region', 'in', []));
        [$sql, $params] = RowScope::sqlCondition($rule, 'json_payload');
        $this->assertStringContainsString('1=0', $sql);
        $this->assertSame([], $params);
    }

    public function testSqlConditionFiltersRealRows(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['region' => 'norte', 'g_a/equipo' => '1']);
        $this->addSubmission($formId, ['region' => 'norte', 'g_a/equipo' => '2']);
        $this->addSubmission($formId, ['region' => 'sur',   'g_a/equipo' => '1']);
        $this->addSubmission($formId, ['region' => 'este']); // sin g_a/equipo

        // region IN ('norte') → 2 filas
        $this->assertSame(2, $this->scopedCount($formId, RowScope::normalize(self::rule('region', 'in', ['norte']))));

        // region=norte Y g_a/equipo=2 → 1 fila (ruta de grupo, barra escapada)
        $rule2 = RowScope::normalize(['groups' => [['match' => 'all', 'conditions' => [
            ['field' => 'region', 'op' => 'in', 'values' => ['norte']],
            ['field' => 'g_a/equipo', 'op' => 'in', 'values' => ['2']],
        ]]]]);
        $this->assertSame(1, $this->scopedCount($formId, $rule2));

        // sin filtro → todas
        $this->assertSame(4, $this->scopedCount($formId, null));

        // fail-closed → 0
        $this->assertSame(0, $this->scopedCount($formId, RowScope::normalize(self::rule('region', 'in', []))));
    }

    // ---------- PARIDAD SQL ≡ PHP ----------

    public function testSqlPhpParityAcrossOperators(): void
    {
        $formId = $this->makeForm();
        $payloads = [
            ['region' => 'norte', 'equipo' => '1', 'edad' => 25, 'fecha' => '2024-03-01', 'sel' => 'a b', 'nota' => 'hola'],
            ['region' => 'norte', 'equipo' => '2', 'edad' => 17, 'fecha' => '2023-11-15', 'sel' => 'b c', 'nota' => ''],
            ['region' => 'sur',   'equipo' => '1', 'edad' => 40, 'fecha' => '2025-01-01', 'sel' => 'c',   'nota' => 'x'],
            ['region' => 'sur',   'equipo' => '3', 'edad' => 18, 'fecha' => '2024-01-01', 'sel' => 'a c d'],
            ['region' => 'este',  'edad' => 'abc', 'sel' => '',  'nota' => 'y'], // sin equipo/fecha; edad no numérica
            ['equipo' => '2', 'edad' => 9, 'fecha' => '2026-12-31'],              // sin region/sel/nota
        ];
        $uids = [];
        foreach ($payloads as $p) {
            $uids[] = $this->addSubmission($formId, $p);
        }
        $decoded = array_map(fn($p) => $p, $payloads);

        $rules = [
            self::rule('region', 'in', ['norte', 'sur']),
            self::rule('region', 'nin', ['norte']),
            self::rule('edad', 'gte', ['18']),
            self::rule('edad', 'lt', ['18']),
            self::rule('fecha', 'gte', ['2024-01-01']),
            self::rule('fecha', 'lt', ['2024-01-01']),
            self::rule('nota', 'empty'),
            self::rule('nota', 'not_empty'),
            self::rule('sel', 'has_any', ['a', 'z']),
            self::rule('sel', 'has_all', ['a', 'c']),
            self::rule('sel', 'has_none', ['a']),
            // DNF: (region=norte Y edad>=18) O (region=sur Y equipo=1)
            ['match' => 'any', 'groups' => [
                ['match' => 'all', 'conditions' => [
                    ['field' => 'region', 'op' => 'in', 'values' => ['norte']],
                    ['field' => 'edad', 'op' => 'gte', 'values' => ['18']],
                ]],
                ['match' => 'all', 'conditions' => [
                    ['field' => 'region', 'op' => 'in', 'values' => ['sur']],
                    ['field' => 'equipo', 'op' => 'in', 'values' => ['1']],
                ]],
            ]],
            // grupo OR mixto con vacío
            ['groups' => [['match' => 'any', 'conditions' => [
                ['field' => 'nota', 'op' => 'empty'],
                ['field' => 'edad', 'op' => 'gte', 'values' => ['40']],
            ]]]],
        ];

        foreach ($rules as $i => $raw) {
            $rule = RowScope::normalize($raw);

            // Conjunto esperado por PHP.
            $phpUids = [];
            foreach ($decoded as $idx => $payload) {
                if (RowScope::matches($rule, $payload)) {
                    $phpUids[] = $uids[$idx];
                }
            }
            sort($phpUids);

            // Conjunto devuelto por SQL.
            [$sql, $params] = RowScope::sqlCondition($rule, 'json_payload');
            $rows = DB::run(
                "SELECT submission_uid FROM submissions_cache WHERE form_id = ? AND $sql",
                array_merge([$formId], $params)
            )->fetchAll();
            $sqlUids = array_map(fn($r) => $r['submission_uid'], $rows);
            sort($sqlUids);

            $this->assertSame($phpUids, $sqlUids, "Paridad SQL≡PHP fallida en la regla #$i");
        }
    }

    private function scopedCount(int $formId, ?array $rule): int
    {
        [$sql, $params] = RowScope::sqlCondition($rule, 'json_payload');
        return (int) DB::run(
            "SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ? AND $sql",
            array_merge([$formId], $params)
        )->fetch()['c'];
    }

    /** Pequeño helper para silenciar advertencias de tipo en asserts booleanos. */
    private function bool(bool $b): bool
    {
        return $b;
    }
}
