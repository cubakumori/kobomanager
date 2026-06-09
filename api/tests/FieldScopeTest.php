<?php

declare(strict_types=1);

/**
 * Tests de los permisos a nivel de columna (lib/FieldScope): normalización, regla
 * por usuario/enlace, recorte del payload (datos + adjuntos + geo), recorte del
 * esquema resuelto y búsqueda restringida a campos visibles contra la BD de test.
 */
final class FieldScopeTest extends DbTestCase
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

    private function setFieldFilter(int $userId, int $formId, ?array $filter): void
    {
        DB::run(
            'INSERT INTO user_form_permissions (user_id, form_id, can_view, field_filter)
             VALUES (?, ?, 1, ?)',
            [$userId, $formId, $filter !== null ? json_encode($filter) : null]
        );
    }

    // ---------- normalize ----------

    public function testNormalizeAcceptsBareListAndObject(): void
    {
        $this->assertSame(['hidden' => ['a', 'b'], 'readonly' => []], FieldScope::normalize(['a', 'b']));
        $this->assertSame(['hidden' => ['a', 'b'], 'readonly' => []], FieldScope::normalize(['hidden' => ['a', 'b']]));
    }

    public function testNormalizeDedupesAndDropsEmptyAndMeta(): void
    {
        $rule = FieldScope::normalize(['hidden' => ['region', 'region', '  ', '_submitted_by', 'g_a/edad']]);
        // duplicado fusionado; vacío y metadato (_*) descartados
        $this->assertSame(['hidden' => ['region', 'g_a/edad'], 'readonly' => []], $rule);
    }

    public function testNormalizeEmptyOrInvalidReturnsNull(): void
    {
        $this->assertNull(FieldScope::normalize(null));
        $this->assertNull(FieldScope::normalize('nope'));
        $this->assertNull(FieldScope::normalize([]));
        $this->assertNull(FieldScope::normalize(['hidden' => []]));
        $this->assertNull(FieldScope::normalize(['hidden' => ['_id']])); // solo metadatos
        $this->assertNull(FieldScope::normalize(['other' => ['x']]));
        $this->assertNull(FieldScope::normalize(['hidden' => [], 'readonly' => []]));
    }

    // ---------- readonly (tercer estado: visible pero no editable) ----------

    public function testNormalizeReadonly(): void
    {
        $rule = FieldScope::normalize(['hidden' => ['a'], 'readonly' => ['b', 'b', '_id', ' ']]);
        $this->assertSame(['hidden' => ['a'], 'readonly' => ['b']], $rule);

        // Solo readonly, sin ocultas → regla válida con hidden vacío.
        $rule = FieldScope::normalize(['readonly' => ['b']]);
        $this->assertSame(['hidden' => [], 'readonly' => ['b']], $rule);
    }

    public function testNormalizeHiddenWinsOverReadonly(): void
    {
        // Una clave en ambos: queda solo como oculta.
        $rule = FieldScope::normalize(['hidden' => ['a'], 'readonly' => ['a', 'b']]);
        $this->assertSame(['hidden' => ['a'], 'readonly' => ['b']], $rule);
    }

    public function testReadonlyFieldsAndIsReadonly(): void
    {
        $rule = FieldScope::normalize(['readonly' => ['region']]);
        $this->assertSame(['region'], FieldScope::readonlyFields($rule));
        $this->assertSame([], FieldScope::readonlyFields(null));
        $this->assertTrue(FieldScope::isReadonly($rule, 'region'));
        $this->assertFalse(FieldScope::isReadonly($rule, 'nombre'));
        $this->assertFalse(FieldScope::isReadonly(null, 'region'));
        // readonly NO oculta: el campo sigue visible.
        $this->assertFalse(FieldScope::isHidden($rule, 'region'));
    }

    // ---------- hidden / isHidden ----------

    public function testHiddenAndIsHidden(): void
    {
        $rule = FieldScope::normalize(['region', 'g_a/edad']);
        $this->assertSame(['region', 'g_a/edad'], FieldScope::hidden($rule));
        $this->assertSame([], FieldScope::hidden(null));
        $this->assertTrue(FieldScope::isHidden($rule, 'region'));
        $this->assertFalse(FieldScope::isHidden($rule, 'nombre'));
        $this->assertFalse(FieldScope::isHidden(null, 'region')); // sin restricción
    }

    // ---------- ruleForUser / ruleForLink ----------

    public function testAdminHasNoRestriction(): void
    {
        $formId = $this->makeForm();
        $this->assertNull(FieldScope::ruleForUser(['id' => 1, 'role' => 'admin'], $formId));
    }

    public function testViewerWithoutFilterReturnsNull(): void
    {
        $formId = $this->makeForm();
        $userId = $this->makeUser('viewer');
        $this->setFieldFilter($userId, $formId, null);
        $this->assertNull(FieldScope::ruleForUser(['id' => $userId, 'role' => 'viewer'], $formId));
    }

    public function testViewerWithFilterReturnsNormalizedRule(): void
    {
        $formId = $this->makeForm();
        $userId = $this->makeUser('viewer');
        $this->setFieldFilter($userId, $formId, ['hidden' => ['region']]);
        $rule = FieldScope::ruleForUser(['id' => $userId, 'role' => 'viewer'], $formId);
        $this->assertSame(['hidden' => ['region'], 'readonly' => []], $rule);
    }

    public function testRuleForLink(): void
    {
        $this->assertNull(FieldScope::ruleForLink(['field_filter' => null]));
        $this->assertSame(
            ['hidden' => ['dni'], 'readonly' => []],
            FieldScope::ruleForLink(['field_filter' => json_encode(['hidden' => ['dni']])])
        );
    }

    // ---------- apply (payload) ----------

    public function testApplyNullRuleReturnsPayloadUnchanged(): void
    {
        $payload = ['region' => 'norte', 'dni' => '123'];
        $this->assertSame($payload, FieldScope::apply(null, $payload));
    }

    public function testApplyStripsHiddenDataKeys(): void
    {
        $rule = FieldScope::normalize(['dni']);
        $out  = FieldScope::apply($rule, ['region' => 'norte', 'dni' => '123']);
        $this->assertSame(['region' => 'norte'], $out);
    }

    public function testApplyFiltersAttachmentsOfHiddenFields(): void
    {
        $rule = FieldScope::normalize(['foto']);
        $payload = [
            'nombre' => 'Ana',
            'foto'   => 'foto.jpg',
            '_attachments' => [
                ['uid' => 'att1', 'question_xpath' => 'foto'],
                ['uid' => 'att2', 'question_xpath' => 'documento'],
            ],
        ];
        $out = FieldScope::apply($rule, $payload);
        $this->assertArrayNotHasKey('foto', $out);
        $this->assertCount(1, $out['_attachments']);
        $this->assertSame('att2', $out['_attachments'][0]['uid']);
    }

    public function testApplyDropsGeolocationWhenGeoFieldHidden(): void
    {
        $schema = ['fields' => ['gps' => ['type' => 'geopoint'], 'region' => ['type' => 'select_one r']]];
        $payload = ['gps' => '1 2 0 5', 'region' => 'norte', '_geolocation' => [1.0, 2.0]];

        // Oculto el campo geo → desaparece el valor Y el respaldo _geolocation.
        $out = FieldScope::apply(FieldScope::normalize(['gps']), $payload, $schema);
        $this->assertArrayNotHasKey('gps', $out);
        $this->assertArrayNotHasKey('_geolocation', $out);

        // Oculto un campo NO geo → _geolocation se conserva.
        $out2 = FieldScope::apply(FieldScope::normalize(['region']), $payload, $schema);
        $this->assertArrayHasKey('_geolocation', $out2);
    }

    // ---------- applySchema ----------

    public function testApplySchemaRemovesHiddenLabelsOptionsAndMulti(): void
    {
        $resolved = [
            'labels'  => ['region' => 'Región', 'dni' => 'DNI', 'sel' => 'Sel'],
            'options' => ['region' => ['n' => 'Norte'], 'sel' => ['a' => 'A']],
            'multi'   => ['sel', 'region'],
        ];
        $out = FieldScope::applySchema(FieldScope::normalize(['dni', 'sel']), $resolved);
        $this->assertSame(['region' => 'Región'], $out['labels']);
        $this->assertSame(['region' => ['n' => 'Norte']], $out['options']);
        $this->assertSame(['region'], $out['multi']);
        // Null → sin cambios.
        $this->assertSame($resolved, FieldScope::applySchema(null, $resolved));
    }

    // ---------- visiblePaths ----------

    public function testVisiblePathsExcludesHiddenAndMeta(): void
    {
        $schema = ['fields' => ['nombre' => [], 'dni' => [], 'g/edad' => [], '_id' => []]];
        $rule   = FieldScope::normalize(['dni']);
        $this->assertSame(['nombre', 'g/edad'], FieldScope::visiblePaths($rule, $schema));
        // Sin regla: todos los campos de datos.
        $this->assertSame(['nombre', 'dni', 'g/edad'], FieldScope::visiblePaths(null, $schema));
    }

    // ---------- SubmissionSearch::clauseVisible (no filtra valores ocultos) ----------

    public function testVisibleSearchOnlyMatchesVisibleFields(): void
    {
        $formId = $this->makeForm();
        // Dos envíos: "secreto" aparece solo en el campo OCULTO `dni`.
        $this->addSubmission($formId, ['nombre' => 'Ana',    'dni' => 'secreto']);
        $this->addSubmission($formId, ['nombre' => 'secreto', 'dni' => 'x']);

        $visible = ['nombre']; // `dni` oculto → no se busca en él
        [$sql, $params] = SubmissionSearch::clauseVisible('sc', 'secreto', $visible);
        $count = (int) DB::run(
            "SELECT COUNT(*) AS c FROM submissions_cache sc WHERE sc.form_id = ? AND $sql",
            array_merge([$formId], $params)
        )->fetch()['c'];
        // Solo casa la fila donde 'secreto' está en el campo VISIBLE `nombre`.
        $this->assertSame(1, $count);
    }

    public function testVisibleSearchNoVisibleFieldsMatchesNothing(): void
    {
        [$sql, $params] = SubmissionSearch::clauseVisible('sc', 'x', []);
        $this->assertSame('1=0', $sql);
        $this->assertSame([], $params);
    }

    public function testVisibleSearchMultiWordIsAnd(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['nombre' => 'Ana Maria', 'apellido' => 'Pérez']);
        $this->addSubmission($formId, ['nombre' => 'Ana',       'apellido' => 'Gómez']);

        // "ana perez" → ambos tokens deben aparecer en algún campo visible (AND).
        [$sql, $params] = SubmissionSearch::clauseVisible('sc', 'ana perez', ['nombre', 'apellido']);
        $count = (int) DB::run(
            "SELECT COUNT(*) AS c FROM submissions_cache sc WHERE sc.form_id = ? AND $sql",
            array_merge([$formId], $params)
        )->fetch()['c'];
        $this->assertSame(1, $count);
    }
}
