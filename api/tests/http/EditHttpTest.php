<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: edición de un envío. La escritura va a un STUB de Kobo
 * (tests/kobo_stub.php), que reproduce el contrato real: una edición devuelve un
 * `_uuid` NUEVO y responde HTTP 200 aunque falle (failures>0). Cubre la migración de la
 * clave de caché + el arrastre del historial de revisiones, y la detección de fallos.
 */
final class EditHttpTest extends HttpTestCase
{
    public function testEditMigratesUuidCacheAndReviews(): void
    {
        $adminId = $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId   = $this->seedAccount(); // → stub de Kobo
        $formId  = $this->seedForm($accId);
        $this->seedSubmission($formId, 'e1', ['_id' => 5001, 'name' => 'Ana']);
        // Una revisión previa que debe seguir al envío tras la edición.
        DB::run('INSERT INTO submission_reviews (submission_uid, user_id, status, comment) VALUES (?, ?, ?, ?)',
            ['e1', $adminId, 'on_hold', 'antes de editar']);

        $jar = $this->login('admin@test.local', 'Secret123!');
        $res = $this->request('PUT', 'submissions/e1', ['data' => ['name' => 'Bea']], $jar);

        $this->assertSame(200, $res['status'], $res['raw']);
        $newUid = $res['json']['data']['submission_uid'];
        $this->assertNotSame('e1', $newUid, 'el _uuid debería cambiar tras una edición');

        // Caché: la clave migró al nuevo uid, el valor se actualizó y el payload._uuid coincide.
        $row = DB::run('SELECT submission_uid, json_payload, search_text FROM submissions_cache WHERE form_id = ?', [$formId])->fetch();
        $this->assertSame($newUid, $row['submission_uid']);
        $payload = json_decode($row['json_payload'], true);
        $this->assertSame('Bea', $payload['name']);
        $this->assertSame($newUid, $payload['_uuid']);
        $this->assertStringContainsString('Bea', $row['search_text']);

        // El historial de revisiones siguió al nuevo uid.
        $this->assertSame(0, (int) DB::run('SELECT COUNT(*) c FROM submission_reviews WHERE submission_uid = ?', ['e1'])->fetch()['c']);
        $this->assertSame(1, (int) DB::run('SELECT COUNT(*) c FROM submission_reviews WHERE submission_uid = ?', [$newUid])->fetch()['c']);

        // Rutas: uid viejo → 404, uid nuevo → 200.
        $this->assertSame(404, $this->request('GET', 'submissions/e1', null, $jar)['status']);
        $this->assertSame(200, $this->request('GET', "submissions/$newUid", null, $jar)['status']);
        @unlink($jar);
    }

    public function testBulkFailureSurfacesError(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'e1', ['_id' => 5001, 'name' => 'Ana']);
        $jar = $this->login('admin@test.local', 'Secret123!');

        // El stub devuelve failures>0 ante la clave `force_fail` (HTTP 200) → KOBO_EDIT_FAILED.
        $res = $this->request('PUT', 'submissions/e1', ['data' => ['force_fail' => 'x']], $jar);
        $this->assertSame(502, $res['status']);
        $this->assertSame('KOBO_EDIT_FAILED', $res['json']['error']['code']);

        // La caché no cambió.
        $payload = json_decode(DB::run('SELECT json_payload FROM submissions_cache WHERE submission_uid = ?', ['e1'])->fetch()['json_payload'], true);
        $this->assertSame('Ana', $payload['name']);
        @unlink($jar);
    }

    public function testViewerWithoutEditCannotEdit(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'e1', ['_id' => 5001, 'name' => 'Ana']);
        $this->grant($uid, $formId, view: true, edit: false);
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('PUT', 'submissions/e1', ['data' => ['name' => 'Bea']], $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }

    public function testCannotEditHiddenField(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'e1', ['_id' => 5001, 'name' => 'Ana', 'secret' => 's']);
        $this->grant($uid, $formId, view: true, edit: true, fieldFilter: ['hidden' => ['secret']]);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Editar un campo oculto se comporta como inexistente (404).
        $res = $this->request('PUT', 'submissions/e1', ['data' => ['secret' => 'x']], $jar);
        $this->assertSame(404, $res['status']);
        @unlink($jar);
    }
}
