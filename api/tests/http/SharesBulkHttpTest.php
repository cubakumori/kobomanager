<?php

declare(strict_types=1);

/**
 * Tests de integración HTTP de la creación de enlaces EN LOTE
 * (POST /admin/shares/bulk): un enlace por cada valor elegido de un campo
 * select_one, con su filtro de filas fijado, tope, y validaciones.
 */
final class SharesBulkHttpTest extends HttpTestCase
{
    /** Esquema con un select_one (provincia), un select_multiple (equipo) y texto. */
    private function schemaJson(): string
    {
        return json_encode([
            'fields' => [
                'provincia' => ['type' => 'select_one prov', 'leaf' => 'provincia', 'list' => 'prov', 'label' => ['' => 'Provincia']],
                'equipo'    => ['type' => 'select_multiple eq', 'leaf' => 'equipo', 'list' => 'eq', 'multi' => true, 'label' => ['' => 'Equipo']],
                'nombre'    => ['type' => 'text', 'leaf' => 'nombre', 'label' => ['' => 'Nombre']],
            ],
            'choices' => [
                'prov' => [
                    'PR'  => ['' => 'Pinar del Río'],
                    'HAB' => ['' => 'La Habana'],
                    'MAT' => ['' => 'Matanzas'],
                ],
                'eq' => ['a' => ['' => 'Equipo A'], 'b' => ['' => 'Equipo B']],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function seedSchemaForm(): int
    {
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());
        $this->seedSubmission($formId, 's1', ['_id' => 1, 'provincia' => 'PR', 'nombre' => 'Ana']);
        $this->seedSubmission($formId, 's2', ['_id' => 2, 'provincia' => 'PR', 'nombre' => 'Bea']);
        $this->seedSubmission($formId, 's3', ['_id' => 3, 'provincia' => 'HAB', 'nombre' => 'Cid']);
        return $formId;
    }

    private function adminJar(): string
    {
        $this->seedUser('admin', 'admin@test.local', 'secret123');
        return $this->login('admin@test.local', 'secret123');
    }

    public function testCreatesOneLinkPerValueWithScopedFilter(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();

        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id'           => $formId,
            'distinctive_field' => 'provincia',
            'values'            => ['PR', 'HAB'],
            'label_prefix'      => 'ODS',
            'expose_list'       => true,
        ], $jar);

        $this->assertSame(201, $res['status'], $res['raw']);
        $this->assertSame(2, $res['json']['data']['count']);

        // Cada enlace lleva su etiqueta legible con prefijo.
        $labels = array_column($res['json']['data']['created'], 'label');
        $this->assertContains('ODS Pinar del Río', $labels);
        $this->assertContains('ODS La Habana', $labels);

        // La lista de enlaces refleja el filtro por filas de cada valor.
        $list = $this->request('GET', 'admin/shares', null, $jar);
        $byLabel = [];
        foreach ($list['json']['data']['items'] as $it) {
            $byLabel[$it['label']] = $it;
        }
        $rf = $byLabel['ODS Pinar del Río']['row_filter'];
        $this->assertSame('provincia', $rf['groups'][0]['conditions'][0]['field']);
        $this->assertSame(['PR'], $rf['groups'][0]['conditions'][0]['values']);
    }

    public function testPrefixOptionalUsesPlainLabel(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id' => $formId, 'distinctive_field' => 'provincia',
            'values' => ['MAT'], 'expose_list' => true,
        ], $jar);
        $this->assertSame(201, $res['status'], $res['raw']);
        $this->assertSame('Matanzas', $res['json']['data']['created'][0]['label']);
    }

    public function testBaseRowFilterCombinesInAnd(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id' => $formId, 'distinctive_field' => 'provincia',
            'values' => ['PR'], 'expose_list' => true,
            'row_filter' => ['match' => 'all', 'groups' => [
                ['match' => 'all', 'conditions' => [['field' => 'nombre', 'op' => 'in', 'values' => ['Ana']]]],
            ]],
        ], $jar);
        $this->assertSame(201, $res['status'], $res['raw']);

        $list = $this->request('GET', 'admin/shares', null, $jar);
        $rf = $list['json']['data']['items'][0]['row_filter'];
        // Base (nombre) + distintiva (provincia), ambas en Y en la raíz.
        $this->assertSame('all', $rf['match']);
        $this->assertCount(2, $rf['groups']);
    }

    public function testIgnoresInvalidValuesButKeepsValidOnes(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id' => $formId, 'distinctive_field' => 'provincia',
            'values' => ['PR', 'ZZZ'], 'expose_list' => true, // ZZZ no es opción del campo
        ], $jar);
        $this->assertSame(201, $res['status'], $res['raw']);
        $this->assertSame(1, $res['json']['data']['count']);
    }

    public function testRejectsSelectMultipleField(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id' => $formId, 'distinctive_field' => 'equipo',
            'values' => ['a'], 'expose_list' => true,
        ], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
    }

    public function testRejectsTextField(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id' => $formId, 'distinctive_field' => 'nombre',
            'values' => ['Ana'], 'expose_list' => true,
        ], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
    }

    public function testRejectsBaseFilterUsingDistinctiveField(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id' => $formId, 'distinctive_field' => 'provincia',
            'values' => ['PR'], 'expose_list' => true,
            'row_filter' => ['match' => 'all', 'groups' => [
                ['match' => 'all', 'conditions' => [['field' => 'provincia', 'op' => 'in', 'values' => ['HAB']]]],
            ]],
        ], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
    }

    public function testRejectsEmptyValues(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id' => $formId, 'distinctive_field' => 'provincia',
            'values' => [], 'expose_list' => true,
        ], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
    }

    public function testRequiresAtLeastOneExposedView(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('POST', 'admin/shares/bulk', [
            'form_id' => $formId, 'distinctive_field' => 'provincia', 'values' => ['PR'],
        ], $jar);
        $this->assertSame(422, $res['status'], $res['raw']);
    }

    public function testCountsEndpointReturnsPerValueTotals(): void
    {
        $formId = $this->seedSchemaForm();
        $jar    = $this->adminJar();
        $res = $this->request('GET', "admin/forms/$formId/scope-fields?counts=provincia", null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(2, $res['json']['data']['counts']['PR']);
        $this->assertSame(1, $res['json']['data']['counts']['HAB']);
    }
}
