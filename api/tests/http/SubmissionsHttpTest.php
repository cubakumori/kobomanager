<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: lectura de envíos con permisos, scoping por filas (RowScope) y
 * ocultado por columna (FieldScope), más la exportación CSV.
 */
final class SubmissionsHttpTest extends HttpTestCase
{
    public function testListReturnsSeededSubmissions(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'u1', ['_id' => 1, 'name' => 'Ana']);
        $this->seedSubmission($formId, 'u2', ['_id' => 2, 'name' => 'Beto']);
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/submissions", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['json']['data']['total']);
        @unlink($jar);
    }

    public function testRowScopeLimitsListAndDetail(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'in', ['_id' => 1, 'prov' => '1']);
        $this->seedSubmission($formId, 'out', ['_id' => 2, 'prov' => '2']);
        $this->grant($uid, $formId, view: true,
            rowFilter: ['conditions' => [['field' => 'prov', 'values' => ['1']]]]);
        $jar = $this->login('v@test.local', 'Secret123!');

        // La lista solo cuenta el envío en alcance.
        $list = $this->request('GET', "forms/$formId/submissions", null, $jar);
        $this->assertSame(1, $list['json']['data']['total']);

        // El detalle del envío fuera de alcance es 404; el de dentro, 200.
        $this->assertSame(200, $this->request('GET', 'submissions/in', null, $jar)['status']);
        $this->assertSame(404, $this->request('GET', 'submissions/out', null, $jar)['status']);
        @unlink($jar);
    }

    public function testFieldScopeHidesColumnInListAndDetail(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'u1', ['_id' => 1, 'name' => 'Ana', 'secret' => 'TOPSECRET']);
        $this->grant($uid, $formId, view: true, fieldFilter: ['hidden' => ['secret']]);
        $jar = $this->login('v@test.local', 'Secret123!');

        // El detalle no incluye el campo oculto en `data`.
        $detail = $this->request('GET', 'submissions/u1', null, $jar);
        $this->assertSame(200, $detail['status']);
        $this->assertArrayHasKey('name', $detail['json']['data']['data']);
        $this->assertArrayNotHasKey('secret', $detail['json']['data']['data']);

        // La lista tampoco lo expone.
        $list = $this->request('GET', "forms/$formId/submissions", null, $jar);
        $this->assertArrayNotHasKey('secret', $list['json']['data']['items'][0]['data']);
        @unlink($jar);
    }

    public function testExportCsvServesAttachmentWithBom(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'u1', ['_id' => 1, 'name' => 'Ana']);
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/export", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $res['raw']); // BOM UTF-8
        $this->assertStringContainsString('Ana', $res['raw']);
        @unlink($jar);
    }
}
