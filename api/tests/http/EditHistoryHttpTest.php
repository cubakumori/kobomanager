<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: historial de edición de un envío reconstruido por la cadena de uuid
 * (audit_log.action='edit' con detail.new_uid). GET /submissions/{id}/history.
 */
final class EditHistoryHttpTest extends HttpTestCase
{
    /** Inserta una entrada de audit 'edit' (uid antiguo → new_uid) con before/after. */
    private function seedEdit(int $userId, int $formId, string $fromUid, string $newUid, array $before, array $after): void
    {
        DB::run(
            "INSERT INTO audit_log (user_id, form_id, submission_uid, action, detail)
             VALUES (?, ?, ?, 'edit', ?)",
            [$userId, $formId, $fromUid, json_encode(['before' => $before, 'after' => $after, 'new_uid' => $newUid])]
        );
    }

    public function testHistoryWalksUuidChainNewestFirst(): void
    {
        $adminId = $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId   = $this->seedAccount();
        $formId  = $this->seedForm($accId);
        // Estado actual en caché: uid C (tras dos ediciones A→B→C).
        $this->seedSubmission($formId, 'C', ['_id' => 1, 'name' => 'Carla']);
        $this->seedEdit($adminId, $formId, 'A', 'B', ['name' => 'Ana'],   ['name' => 'Bea']);
        $this->seedEdit($adminId, $formId, 'B', 'C', ['name' => 'Bea'],   ['name' => 'Carla']);

        $jar = $this->login('admin@test.local', 'Secret123!');
        $res = $this->request('GET', 'submissions/C/history', null, $jar);

        $this->assertSame(200, $res['status'], $res['raw']);
        $edits = $res['json']['data']['edits'];
        $this->assertCount(2, $edits);
        // Orden: la edición más reciente (B→C) primero.
        $this->assertSame('name', $edits[0]['changes'][0]['field']);
        $this->assertSame('Bea', $edits[0]['changes'][0]['from']);
        $this->assertSame('Carla', $edits[0]['changes'][0]['to']);
        // La anterior (A→B).
        $this->assertSame('Ana', $edits[1]['changes'][0]['from']);
        $this->assertSame('Bea', $edits[1]['changes'][0]['to']);
        @unlink($jar);
    }

    public function testHistoryEmptyWhenNoEdits(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'X', ['_id' => 1, 'name' => 'Ana']);
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', 'submissions/X/history', null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $res['json']['data']['edits']);
        @unlink($jar);
    }

    public function testHistoryRequiresEditPermission(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'X', ['_id' => 1, 'name' => 'Ana']);
        $this->grant($uid, $formId, view: true, edit: false);
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('GET', 'submissions/X/history', null, $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }
}
