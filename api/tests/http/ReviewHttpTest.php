<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: revisión interna de envíos (individual y en lote).
 * La revisión vive solo en BD local (no toca Kobo). Comprueba permisos (can_validate)
 * y el scoping por filas (un envío fuera de alcance se comporta como inexistente).
 */
final class ReviewHttpTest extends HttpTestCase
{
    private function seedFormWithSubmission(string $uid, array $extra = []): array
    {
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, $uid, array_merge(['_id' => 1001, 'prov' => '1'], $extra));
        return [$accId, $formId];
    }

    public function testAdminCanReviewSingle(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        [, $formId] = $this->seedFormWithSubmission('uid-1');
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('POST', 'submissions/uid-1/review', ['status' => 'approved', 'comment' => 'ok'], $jar);
        $this->assertSame(201, $res['status']);
        $this->assertSame('approved', $res['json']['data']['review_status']);

        $row = DB::run('SELECT status FROM submission_reviews WHERE submission_uid = ?', ['uid-1'])->fetch();
        $this->assertSame('approved', $row['status']);
        @unlink($jar);
    }

    public function testViewerWithoutValidateCannotReview(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        [, $formId] = $this->seedFormWithSubmission('uid-1');
        $this->grant($uid, $formId, view: true, validate: false);
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('POST', 'submissions/uid-1/review', ['status' => 'approved'], $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }

    public function testViewerWithValidateCanReview(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        [, $formId] = $this->seedFormWithSubmission('uid-1');
        $this->grant($uid, $formId, view: true, validate: true);
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('POST', 'submissions/uid-1/review', ['status' => 'on_hold'], $jar);
        $this->assertSame(201, $res['status']);
        @unlink($jar);
    }

    public function testOutOfScopeSubmissionIs404ForReview(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        // Dos envíos; el viewer solo ve prov=1.
        $this->seedSubmission($formId, 'uid-in', ['_id' => 1, 'prov' => '1']);
        $this->seedSubmission($formId, 'uid-out', ['_id' => 2, 'prov' => '2']);
        $this->grant($uid, $formId, view: true, validate: true,
            rowFilter: ['conditions' => [['field' => 'prov', 'values' => ['1']]]]);
        $jar = $this->login('v@test.local', 'Secret123!');

        // En alcance → 201; fuera de alcance → 404 (como inexistente).
        $this->assertSame(201, $this->request('POST', 'submissions/uid-in/review', ['status' => 'approved'], $jar)['status']);
        $this->assertSame(404, $this->request('POST', 'submissions/uid-out/review', ['status' => 'approved'], $jar)['status']);
        @unlink($jar);
    }

    public function testArchivedFormRejectsReview(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        [, $formId] = $this->seedFormWithSubmission('uid-1');
        DB::run('UPDATE forms SET deployment_status = ? WHERE id = ?', ['archived', $formId]);
        $jar = $this->login('admin@test.local', 'Secret123!');

        // Individual y por lotes → 409 FORM_ARCHIVED; no se crea ninguna revisión.
        $single = $this->request('POST', 'submissions/uid-1/review', ['status' => 'approved'], $jar);
        $this->assertSame(409, $single['status']);
        $this->assertSame('FORM_ARCHIVED', $single['json']['error']['code']);

        $batch = $this->request('POST', "forms/$formId/review", ['uids' => ['uid-1'], 'status' => 'approved'], $jar);
        $this->assertSame(409, $batch['status']);
        $this->assertSame('FORM_ARCHIVED', $batch['json']['error']['code']);

        $this->assertFalse((bool) DB::run('SELECT 1 FROM submission_reviews WHERE submission_uid = ?', ['uid-1'])->fetch());
        @unlink($jar);
    }

    public function testBatchReviewAppliesAndSkips(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'b1', ['_id' => 1, 'prov' => '1']);
        $this->seedSubmission($formId, 'b2', ['_id' => 2, 'prov' => '1']);
        $jar = $this->login('admin@test.local', 'Secret123!');

        // b1 y b2 existen; 'ghost' no → applied=2, skipped=1.
        $res = $this->request('POST', "forms/$formId/review",
            ['status' => 'rejected', 'uids' => ['b1', 'b2', 'ghost'], 'comment' => 'lote'], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['json']['data']['applied']);
        $this->assertSame(1, $res['json']['data']['skipped']);
        @unlink($jar);
    }
}
