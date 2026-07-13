<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Normalización de preguntas score («Rating» del builder de Kobo) y rank: son GRUPOS
 * cuyas filas comparten una lista de opciones (`kobo--score-choices` / `kobo--rank-items`).
 * Las filas deben registrarse en su ruta completa (`Q6/carrt`, la clave real del payload
 * — de esto depende el ocultado de columnas), como select_one con la lista compartida y
 * etiqueta compuesta «{pregunta} · {fila}»; el grupo en sí NO es un campo de datos.
 */
final class FormSchemaScoreTest extends TestCase
{
    private function scoreContent(): array
    {
        return [
            'translations' => [null],
            'survey' => [
                ['type' => 'text', 'name' => 'nombre', 'label' => ['Nombre']],
                ['type' => 'begin_score', 'name' => 'Q6', 'label' => ['6. Condiciones de las infraestructuras:'],
                 'kobo--score-choices' => 'cn7lp84'],
                ['type' => 'score__row', 'name' => 'carrt', 'label' => ['Carreteras']],
                ['type' => 'score__row', 'name' => 'hospt', 'label' => ['Hospitales']],
                ['type' => 'end_score'],
                ['type' => 'text', 'name' => 'despues', 'label' => ['Después']],
            ],
            'choices' => [
                ['list_name' => 'cn7lp84', 'name' => '1', 'label' => ['BUENA']],
                ['list_name' => 'cn7lp84', 'name' => '2', 'label' => ['REGULAR']],
                ['list_name' => 'cn7lp84', 'name' => '3', 'label' => ['MALA']],
            ],
        ];
    }

    public function testScoreRowsRegisterAtFullPathAsSelectOne(): void
    {
        $schema = FormSchema::normalize($this->scoreContent());

        // Las filas viven bajo la ruta del grupo (clave real del payload).
        $this->assertArrayHasKey('Q6/carrt', $schema['fields']);
        $this->assertArrayHasKey('Q6/hospt', $schema['fields']);
        $this->assertArrayNotHasKey('carrt', $schema['fields']);

        $f = $schema['fields']['Q6/carrt'];
        $this->assertSame('select_one', $f['type']);
        $this->assertSame('cn7lp84', $f['list']);
        $this->assertFalse($f['multi']);
        // Etiqueta compuesta «{pregunta} · {fila}».
        $this->assertSame('6. Condiciones de las infraestructuras: · Carreteras', $f['label']['']);
    }

    public function testScoreGroupIsNotADataField(): void
    {
        $schema = FormSchema::normalize($this->scoreContent());
        // El grupo no tiene valor en el payload: registrarlo contaminaría el ranking
        // de no-respuesta.
        $this->assertArrayNotHasKey('Q6', $schema['fields']);
    }

    public function testStackPopsAtEndScore(): void
    {
        $schema = FormSchema::normalize($this->scoreContent());
        // El campo posterior al end_score vuelve a la raíz (la pila quedó equilibrada).
        $this->assertArrayHasKey('despues', $schema['fields']);
        $this->assertArrayNotHasKey('Q6/despues', $schema['fields']);
    }

    public function testResolveGivesLabelsAndOptionsForRows(): void
    {
        $schema   = FormSchema::normalize($this->scoreContent());
        $resolved = FormSchema::resolve($schema, 'es');
        $this->assertSame('6. Condiciones de las infraestructuras: · Carreteras', $resolved['labels']['Q6/carrt']);
        $this->assertSame('MALA', $resolved['options']['Q6/carrt']['3']);
    }

    public function testRankRowsBehaveLikeScoreRows(): void
    {
        $schema = FormSchema::normalize([
            'translations' => [null],
            'survey' => [
                ['type' => 'begin_rank', 'name' => 'R1', 'label' => ['Ordene:'], 'kobo--rank-items' => 'items1'],
                ['type' => 'rank__level', 'name' => 'pos1', 'label' => ['Primero']],
                ['type' => 'end_rank'],
            ],
            'choices' => [
                ['list_name' => 'items1', 'name' => 'agua', 'label' => ['Agua']],
            ],
        ]);
        $this->assertArrayHasKey('R1/pos1', $schema['fields']);
        $this->assertSame('items1', $schema['fields']['R1/pos1']['list']);
        $this->assertSame('Ordene: · Primero', $schema['fields']['R1/pos1']['label']['']);
        $this->assertArrayNotHasKey('R1', $schema['fields']);
    }

    public function testScoreInsideGroupNestsBothPaths(): void
    {
        $schema = FormSchema::normalize([
            'translations' => [null],
            'survey' => [
                ['type' => 'begin_group', 'name' => 'g1'],
                ['type' => 'begin_score', 'name' => 'Q6', 'label' => ['P'], 'kobo--score-choices' => 'l1'],
                ['type' => 'score__row', 'name' => 'a', 'label' => ['A']],
                ['type' => 'end_score'],
                ['type' => 'end_group'],
            ],
            'choices' => [],
        ]);
        $this->assertArrayHasKey('g1/Q6/a', $schema['fields']);
    }
}
