<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del flujo de VACIADO confirmado: el stub de Kobo devuelve 0
 * envíos vivos, así que con caché poblada el sync manual no borra nada y anuncia
 * `wipe_available`; repetirlo con `confirm_wipe: true` vacía la caché (y solo
 * entonces). Cubre el endpoint admin; la lógica compartida con el de viewer vive
 * en SubmissionSync (SyncReconcileTest).
 */
final class SyncWipeHttpTest extends HttpTestCase
{
    private function seedStubForm(): int
    {
        $accId  = $this->seedAccount(); // apunta al stub de Kobo (data vacía)
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'v1', ['_id' => 1, 'q' => 'a']);
        $this->seedSubmission($formId, 'v2', ['_id' => 2, 'q' => 'b']);
        return $formId;
    }

    private function cacheCount(int $formId): int
    {
        return (int) DB::run('SELECT COUNT(*) c FROM submissions_cache WHERE form_id = ?', [$formId])->fetch()['c'];
    }

    public function testSyncAnnouncesWipeButKeepsCache(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'secret123');
        $formId = $this->seedStubForm();
        $jar    = $this->login('admin@test.local', 'secret123');

        $res = $this->request('POST', "admin/forms/$formId/sync", [], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $d = $res['json']['data'];
        $this->assertTrue($d['wipe_available']);
        $this->assertSame(2, $d['cached']);
        $this->assertSame(0, $d['removed']);
        $this->assertFalse($d['wiped']);
        $this->assertSame(2, $this->cacheCount($formId)); // nada borrado sin confirmación
        @unlink($jar);
    }

    public function testConfirmWipeEmptiesCache(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'secret123');
        $formId = $this->seedStubForm();
        $jar    = $this->login('admin@test.local', 'secret123');

        $res = $this->request('POST', "admin/forms/$formId/sync", ['confirm_wipe' => true], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $d = $res['json']['data'];
        $this->assertTrue($d['wiped']);
        $this->assertSame(2, $d['removed']);
        $this->assertSame(0, $this->cacheCount($formId));
        @unlink($jar);
    }
}
