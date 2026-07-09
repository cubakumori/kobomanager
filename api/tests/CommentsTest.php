<?php

declare(strict_types=1);

require_once __DIR__ . '/DbTestCase.php';

/**
 * lib/Comments: reúne los comentarios de submission_reviews de un formulario,
 * agrupados por equipo → encuestador, respetando RowScope/FieldScope.
 */
final class CommentsTest extends DbTestCase
{
    /** Envío con encuestador (campo `enum`) y, opcionalmente, equipo (`team`). */
    private function sub(int $formId, string $uid, string $enum, ?string $team = null): void
    {
        $payload = ['enum' => $enum];
        if ($team !== null) $payload['team'] = $team;
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, NOW())',
            [$formId, $uid, json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** Revisión con comentario (source app + usuario, salvo que se indique kobo). */
    private function review(string $uid, string $status, ?string $comment, ?int $userId = null, string $source = 'app'): void
    {
        DB::run(
            'INSERT INTO submission_reviews (submission_uid, user_id, source, status, comment)
             VALUES (?, ?, ?, ?, ?)',
            [$uid, $userId, $source, $status, $comment]
        );
    }

    private function compute(int $formId, ?array $scope = null, ?string $status = null, ?string $search = null, ?string $teamField = null): array
    {
        return Comments::compute($formId, null, $scope, null, 'es', $teamField, 'enum', $status, $search);
    }

    /** Aplana todos los comentarios del resultado indexados por uid → [textos]. */
    private function commentsByUid(array $q): array
    {
        $out = [];
        foreach ($q['teams'] as $t) {
            foreach ($t['enumerators'] as $e) {
                foreach ($e['comments'] as $c) $out[$c['uid']][] = $c['comment'];
            }
        }
        return $out;
    }

    public function testGroupsByEnumeratorAndCountsOnlyCommented(): void
    {
        $formId = $this->makeForm();
        $this->sub($formId, 's1', 'ana');
        $this->sub($formId, 's2', 'luis');
        $this->sub($formId, 's3', 'ana');
        $this->review('s1', 'approved', 'Bien resuelto');
        $this->review('s2', 'rejected', 'Faltan fotos');
        $this->review('s3', 'approved', null);   // sin comentario → no cuenta
        $this->review('s3', 'on_hold', '');       // comentario vacío → no cuenta

        $q = $this->compute($formId);
        $this->assertSame(2, $q['total']);
        $this->assertNull($q['team_field']); // sin nivel de equipo configurado
        // Un solo grupo (sin equipo), con dos encuestadores.
        $this->assertCount(1, $q['teams']);
        $names = array_column($q['teams'][0]['enumerators'], 'count', 'name');
        $this->assertSame(1, $names['ana']);
        $this->assertSame(1, $names['luis']);
        $byUid = $this->commentsByUid($q);
        $this->assertSame(['Bien resuelto'], $byUid['s1']);
        $this->assertArrayNotHasKey('s3', $byUid);
    }

    public function testMultipleCommentsPerSubmission(): void
    {
        $formId = $this->makeForm();
        $this->sub($formId, 's1', 'ana');
        $this->review('s1', 'on_hold', 'Primera duda');
        $this->review('s1', 'approved', 'Aclarado');

        $q = $this->compute($formId);
        $this->assertSame(2, $q['total']);
        $this->assertSame(['Aclarado', 'Primera duda'], $this->commentsByUid($q)['s1']);
    }

    public function testStatusAndSearchFilters(): void
    {
        $formId = $this->makeForm();
        $this->sub($formId, 's1', 'ana');
        $this->sub($formId, 's2', 'ana');
        $this->review('s1', 'approved', 'todo correcto');
        $this->review('s2', 'rejected', 'foto borrosa');

        $this->assertSame(1, $this->compute($formId, null, 'rejected')['total']);
        $this->assertSame(['s2'], array_keys($this->commentsByUid($this->compute($formId, null, 'rejected'))));
        $this->assertSame(1, $this->compute($formId, null, null, 'borrosa')['total']);
        $this->assertSame(0, $this->compute($formId, null, null, 'inexistente')['total']);
    }

    public function testRowScopeExcludesOutOfScopeComments(): void
    {
        $formId = $this->makeForm();
        $this->sub($formId, 's1', 'ana');
        $this->sub($formId, 's2', 'luis');
        $this->review('s1', 'approved', 'de ana');
        $this->review('s2', 'approved', 'de luis');

        // Scope: solo envíos de 'ana'.
        $scope = RowScope::normalize(['match' => 'all', 'groups' => [
            ['match' => 'all', 'conditions' => [['field' => 'enum', 'op' => 'in', 'values' => ['ana']]]],
        ]]);
        $q = $this->compute($formId, $scope);
        $this->assertSame(1, $q['total']);
        $this->assertSame(['s1'], array_keys($this->commentsByUid($q)));
    }

    public function testKoboSourceCommentIncludedWithoutAuthor(): void
    {
        $formId = $this->makeForm();
        $this->sub($formId, 's1', 'ana');
        $this->review('s1', 'approved', 'importado de kobo', null, 'kobo');

        $q = $this->compute($formId);
        $c = $q['teams'][0]['enumerators'][0]['comments'][0];
        $this->assertSame('kobo', $c['source']);
        $this->assertNull($c['author']);
    }

    public function testCommentOfAnotherFormExcluded(): void
    {
        $formA = $this->makeForm();
        $formB = $this->makeForm();
        $this->sub($formB, 'sB', 'ana');
        $this->review('sB', 'approved', 'del form B');

        $this->assertSame(0, $this->compute($formA)['total']);
        $this->assertSame(1, $this->compute($formB)['total']);
    }

    public function testTeamLevelWhenTeamFieldConfigured(): void
    {
        $formId = $this->makeForm();
        $this->sub($formId, 's1', 'ana', 'norte');
        $this->sub($formId, 's2', 'luis', 'sur');
        $this->review('s1', 'approved', 'a');
        $this->review('s2', 'approved', 'b');

        $q = $this->compute($formId, null, null, null, 'team');
        $this->assertNotNull($q['team_field']);
        $this->assertCount(2, $q['teams']);
        $teamNames = array_column($q['teams'], 'name');
        $this->assertContains('norte', $teamNames);
        $this->assertContains('sur', $teamNames);
    }
}
