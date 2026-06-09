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

    public function testReadonlyFieldStaysVisibleAndIsFlagged(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'r1', ['_id' => 1, 'name' => 'Ana', 'dni' => '123']);
        $this->grant($uid, $formId, view: true, edit: true, fieldFilter: ['readonly' => ['dni']]);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Solo lectura ≠ oculto: el campo SÍ aparece en data y el detalle lo declara.
        $detail = $this->request('GET', 'submissions/r1', null, $jar);
        $this->assertSame(200, $detail['status']);
        $this->assertSame('123', $detail['json']['data']['data']['dni']);
        $this->assertSame(['dni'], $detail['json']['data']['readonly_fields']);
        @unlink($jar);
    }

    public function testStatsExcludeHiddenQuestionAndItsAttachments(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $schema = json_encode(['fields' => [
            'estado' => ['type' => 'select_one yesno', 'leaf' => 'estado'],
            'region' => ['type' => 'select_one r', 'leaf' => 'region'],
        ]]);
        $formId = $this->seedForm($accId, null, $schema);
        $this->seedSubmission($formId, 's1', [
            '_id' => 1, 'estado' => 'si', 'region' => 'norte',
            '_attachments' => [['uid' => 'a1', 'question_xpath' => 'estado', 'media_file_basename' => 'x.jpg', 'mimetype' => 'image/jpeg', 'download_url' => 'http://x/a1']],
        ]);
        $this->seedSubmission($formId, 's2', ['_id' => 2, 'estado' => 'no', 'region' => 'sur']);
        $this->grant($uid, $formId, view: true, fieldFilter: ['hidden' => ['estado']]);
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/stats", null, $jar);
        $this->assertSame(200, $res['status']);
        $fieldsInStats = array_column($res['json']['data']['by_question'], 'field');
        // La pregunta oculta no aparece en los agregados; la visible sí.
        $this->assertNotContains('estado', $fieldsInStats);
        $this->assertContains('region', $fieldsInStats);
        // El adjunto del campo oculto tampoco cuenta.
        $this->assertSame(0, $res['json']['data']['attachments']['with']);
        @unlink($jar);
    }

    public function testAdvancedFilterRestrictsListAndCannotEscapeRowScope(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'f1', ['_id' => 1, 'region' => 'norte', 'estado' => 'ok']);
        $this->seedSubmission($formId, 'f2', ['_id' => 2, 'region' => 'norte', 'estado' => 'mal']);
        $this->seedSubmission($formId, 'f3', ['_id' => 3, 'region' => 'sur', 'estado' => 'ok']);
        // Scope obligatorio: solo región norte.
        $this->grant($uid, $formId, view: true, rowFilter: ['conditions' => [['field' => 'region', 'values' => ['norte']]]]);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Filtro avanzado: estado=ok → restringe DENTRO del scope (f1 únicamente).
        $filter = json_encode(['groups' => [['conditions' => [['field' => 'estado', 'op' => 'in', 'values' => ['ok']]]]]]);
        $res = $this->request('GET', "forms/$formId/submissions?filter=" . urlencode($filter), null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['json']['data']['total']);
        $this->assertSame('f1', $res['json']['data']['items'][0]['submission_uid']);

        // El filtro no puede AMPLIAR el scope: pedir región sur da 0 (f3 está fuera de alcance).
        $filter = json_encode(['groups' => [['conditions' => [['field' => 'region', 'op' => 'in', 'values' => ['sur']]]]]]);
        $res = $this->request('GET', "forms/$formId/submissions?filter=" . urlencode($filter), null, $jar);
        $this->assertSame(0, $res['json']['data']['total']);

        // El export respeta el mismo filtro (solo la fila f1).
        $filter = json_encode(['groups' => [['conditions' => [['field' => 'estado', 'op' => 'in', 'values' => ['ok']]]]]]);
        $csv = $this->request('GET', "forms/$formId/export?filter=" . urlencode($filter), null, $jar);
        $this->assertSame(200, $csv['status']);
        $this->assertSame(2, substr_count(trim($csv['raw']), "\n") + 1); // cabecera + 1 fila
        @unlink($jar);
    }

    public function testAdvancedFilterOnHiddenFieldIsRejected(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'h1', ['_id' => 1, 'name' => 'Ana', 'secret' => 's1']);
        $this->grant($uid, $formId, view: true, fieldFilter: ['hidden' => ['secret']]);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Filtrar por un campo oculto revelaría su contenido → 422.
        $filter = json_encode(['groups' => [['conditions' => [['field' => 'secret', 'op' => 'in', 'values' => ['s1']]]]]]);
        foreach (['submissions', 'export'] as $ep) {
            $res = $this->request('GET', "forms/$formId/$ep?filter=" . urlencode($filter), null, $jar);
            $this->assertSame(422, $res['status'], $ep);
        }
        @unlink($jar);
    }

    public function testScopeFieldsForViewerExcludesHiddenAndScopesValues(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $schema = json_encode(['fields' => [
            'region' => ['type' => 'select_one r', 'leaf' => 'region'],
            'secret' => ['type' => 'text', 'leaf' => 'secret'],
            'estado' => ['type' => 'text', 'leaf' => 'estado'],
        ]]);
        $formId = $this->seedForm($accId, null, $schema);
        $this->seedSubmission($formId, 'v1', ['_id' => 1, 'region' => 'norte', 'estado' => 'ok']);
        $this->seedSubmission($formId, 'v2', ['_id' => 2, 'region' => 'sur', 'estado' => 'fuera']);
        $this->grant(
            $uid, $formId, view: true,
            rowFilter: ['conditions' => [['field' => 'region', 'values' => ['norte']]]],
            fieldFilter: ['hidden' => ['secret']]
        );
        $jar = $this->login('v@test.local', 'Secret123!');

        // La lista de campos excluye el oculto.
        $res = $this->request('GET', "forms/$formId/scope-fields", null, $jar);
        $this->assertSame(200, $res['status']);
        $keys = array_column($res['json']['data']['fields'], 'key');
        $this->assertContains('region', $keys);
        $this->assertNotContains('secret', $keys);

        // Los valores sugeridos respetan el scope por filas (no se fuga «fuera»).
        $res = $this->request('GET', "forms/$formId/scope-fields?values=estado", null, $jar);
        $this->assertSame(['ok'], $res['json']['data']['values']);

        // Pedir valores de un campo oculto → 404.
        $res = $this->request('GET', "forms/$formId/scope-fields?values=secret", null, $jar);
        $this->assertSame(404, $res['status']);
        @unlink($jar);
    }

    public function testSortByCalculatedDurationIsGlobal(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId); // schema null → start/end por convención
        // Tres envíos con duraciones 10s / 1000s / 100s (start/end ISO en el payload).
        $this->seedSubmission($formId, 'd10',   ['_id' => 1, 'start' => '2024-01-01T10:00:00', 'end' => '2024-01-01T10:00:10']);
        $this->seedSubmission($formId, 'd1000', ['_id' => 2, 'start' => '2024-01-01T10:00:00', 'end' => '2024-01-01T10:16:40']);
        $this->seedSubmission($formId, 'd100',  ['_id' => 3, 'start' => '2024-01-01T10:00:00', 'end' => '2024-01-01T10:01:40']);
        $jar = $this->login('admin@test.local', 'Secret123!');

        $desc = $this->request('GET', "forms/$formId/submissions?sort=duration_desc", null, $jar);
        $this->assertSame(200, $desc['status']);
        $order = array_map(fn($i) => $i['submission_uid'], $desc['json']['data']['items']);
        $this->assertSame(['d1000', 'd100', 'd10'], $order);

        $asc = $this->request('GET', "forms/$formId/submissions?sort=duration_asc", null, $jar);
        $orderAsc = array_map(fn($i) => $i['submission_uid'], $asc['json']['data']['items']);
        $this->assertSame(['d10', 'd100', 'd1000'], $orderAsc);
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
