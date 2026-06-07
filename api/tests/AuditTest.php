<?php

declare(strict_types=1);

/** Consulta paginada/filtrada del registro de auditoría (Audit::query). */
final class AuditTest extends DbTestCase
{
    public function testForcedUserIdReturnsOnlyOwnRows(): void
    {
        $me     = $this->makeUser('viewer');
        $other  = $this->makeUser('viewer');
        $formId = $this->makeForm();

        Audit::log($me, 'view', $formId, 'sub-a');
        Audit::log($me, 'edit', $formId, 'sub-a', ['before' => 1, 'after' => 2]);
        Audit::log($other, 'view', $formId, 'sub-b');

        $res = Audit::query(['user_id' => $me], true);

        $this->assertSame(2, $res['total']);
        foreach ($res['items'] as $row) {
            $this->assertSame($me, $row['user_id']);
        }
    }

    public function testActionFilterAndScopedActionsList(): void
    {
        $me    = $this->makeUser('viewer');
        $other = $this->makeUser('viewer');

        Audit::log($me, 'view');
        Audit::log($me, 'edit');
        Audit::log($other, 'download_attachment');

        // Filtro por acción exacta.
        $res = Audit::query(['user_id' => $me, 'action' => 'edit'], true);
        $this->assertSame(1, $res['total']);
        $this->assertSame('edit', $res['items'][0]['action']);

        // La lista de acciones, con scope al usuario, no incluye las de otros.
        $all = Audit::query(['user_id' => $me], true);
        sort($all['actions']);
        $this->assertSame(['edit', 'view'], $all['actions']);
        $this->assertNotContains('download_attachment', $all['actions']);
    }

    public function testFormFilterAndPagination(): void
    {
        $me     = $this->makeUser('viewer');
        $formA  = $this->makeForm();
        $formB  = $this->makeForm();

        Audit::log($me, 'view', $formA, 'a1');
        Audit::log($me, 'view', $formA, 'a2');
        Audit::log($me, 'view', $formB, 'b1');

        $res = Audit::query(['user_id' => $me, 'form_id' => $formA], true);
        $this->assertSame(2, $res['total']);

        // Paginación: per_page=1 → una fila por página, total intacto.
        $page1 = Audit::query(['user_id' => $me, 'per_page' => 1, 'page' => 1], true);
        $this->assertSame(3, $page1['total']);
        $this->assertCount(1, $page1['items']);
    }
}
