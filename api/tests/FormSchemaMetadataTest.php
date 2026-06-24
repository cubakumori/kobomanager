<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Detección de campos metadato (no editables) y su registro en el esquema normalizado.
 */
final class FormSchemaMetadataTest extends TestCase
{
    public function testStructuralMetadataKeys(): void
    {
        foreach (['_id', '_uuid', '_submission_time', '__version__',
                  'meta/instanceID', 'meta/rootUuid', 'formhub/uuid'] as $k) {
            $this->assertTrue(FormSchema::isMetadataField($k), "$k debería ser metadato");
        }
    }

    public function testStandardLeafNamesAreMetadata(): void
    {
        foreach (['start', 'end', 'today', 'deviceid', 'subscriberid', 'simserial',
                  'phonenumber', 'username', 'audit'] as $k) {
            $this->assertTrue(FormSchema::isMetadataField($k), "$k debería ser metadato");
        }
        // También con prefijo de grupo.
        $this->assertTrue(FormSchema::isMetadataField('grp/today'));
    }

    public function testQuestionsAreNotMetadata(): void
    {
        foreach (['nombre', 'edad', 'g_persona/prov', 'satisfaccion'] as $k) {
            $this->assertFalse(FormSchema::isMetadataField($k), "$k NO debería ser metadato");
        }
    }

    public function testSchemaMetaFieldsCatchCustomNames(): void
    {
        // Un metadato con nombre personalizado solo se detecta vía el esquema.
        $schema = ['meta_fields' => ['g/inicio_raro']];
        $this->assertFalse(FormSchema::isMetadataField('g/inicio_raro'));
        $this->assertTrue(FormSchema::isMetadataField('g/inicio_raro', $schema));
    }

    public function testNormalizeRecordsMetaFields(): void
    {
        $content = [
            'translations' => [null],
            'survey' => [
                ['type' => 'start', 'name' => 'start'],
                ['type' => 'end', 'name' => 'end'],
                ['type' => 'today', 'name' => 'today'],
                ['type' => 'deviceid', 'name' => 'deviceid'],
                ['type' => 'calculate', 'name' => 'edad_calc'],
                ['type' => 'note', 'name' => 'aviso'],
                ['type' => 'text', 'name' => 'nombre', 'label' => 'Nombre'],
                ['type' => 'integer', 'name' => 'edad', 'label' => 'Edad'],
            ],
            'choices' => [],
        ];
        $schema = FormSchema::normalize($content);

        $meta = $schema['meta_fields'];
        foreach (['start', 'end', 'today', 'deviceid', 'edad_calc'] as $m) {
            $this->assertContains($m, $meta, "$m debería estar en meta_fields");
        }
        $this->assertNotContains('aviso', $meta, 'note no tiene valor → no se registra');
        $this->assertNotContains('nombre', $meta);
        // Las preguntas reales siguen en fields.
        $this->assertArrayHasKey('nombre', $schema['fields']);
        $this->assertArrayHasKey('edad', $schema['fields']);
    }
}
