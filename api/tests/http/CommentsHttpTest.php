<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: panel de comentarios de revisión (GET /forms/{id}/comments),
 * agrupados por equipo/encuestador. Requiere can_view.
 */
final class CommentsHttpTest extends HttpTestCase
{
    private function review(string $uid, string $status, string $comment, ?int $userId = null): void
    {
        DB::run(
            'INSERT INTO submission_reviews (submission_uid, user_id, source, status, comment)
             VALUES (?, ?, ?, ?, ?)',
            [$uid, $userId, 'app', $status, $comment]
        );
    }

    private function seedFormWithComments(int $accId): int
    {
        $formId = $this->seedForm($accId);
        DB::run('UPDATE forms SET stats_enumerator_field = ? WHERE id = ?', ['enum', $formId]);
        $this->seedSubmission($formId, 's1', ['_id' => 1, 'enum' => 'ana']);
        $this->seedSubmission($formId, 's2', ['_id' => 2, 'enum' => 'luis']);
        $this->review('s1', 'approved', 'todo correcto');
        $this->review('s2', 'rejected', 'foto borrosa');
        return $formId;
    }

    public function testListsGroupedComments(): void
    {
        $uid    = $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedFormWithComments($accId);
        $jar    = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/comments", null, $jar);
        $this->assertSame(200, $res['status']);
        $d = $res['json']['data'];
        $this->assertSame(2, $d['total']);
        $this->assertCount(1, $d['teams']); // sin nivel de equipo
        $names = array_column($d['teams'][0]['enumerators'], 'count', 'name');
        $this->assertSame(1, $names['ana']);
        $this->assertSame(1, $names['luis']);
        @unlink($jar);
    }

    public function testStatusAndSearchFilters(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedFormWithComments($accId);
        $jar    = $this->login('admin@test.local', 'Secret123!');

        $byStatus = $this->request('GET', "forms/$formId/comments?status=rejected", null, $jar);
        $this->assertSame(1, $byStatus['json']['data']['total']);
        $bySearch = $this->request('GET', "forms/$formId/comments?search=borrosa", null, $jar);
        $this->assertSame(1, $bySearch['json']['data']['total']);
        $none = $this->request('GET', "forms/$formId/comments?search=inexistente", null, $jar);
        $this->assertSame(0, $none['json']['data']['total']);
        @unlink($jar);
    }

    public function testViewerWithoutPermissionForbidden(): void
    {
        $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedFormWithComments($accId);
        $jar    = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/comments", null, $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }
}
