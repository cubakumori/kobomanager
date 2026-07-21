<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Regresión del TOPE DE PÁGINA DEL SERVIDOR de Kobo (jul-2026): el servidor
 * devuelve como mucho N filas por página aunque el cliente pida limit=10000
 * (verificado contra Kobo real: 10000 pedidas → 1000 + `next`). El cliente
 * interpretaba «menos filas que el limit pedido» como última página, así que:
 *   - el sync completo importaba solo la primera página;
 *   - el barrido de bajas usaba una lista de _id truncada y «retiraba» de la
 *     caché todo lo que quedara fuera de ella (síntoma real: caché clavada en
 *     1000 con «237 sincronizados · 237 retirados» en cada sync).
 * El stub reproduce el contrato (asset aPage{N}: N filas, tope 10/página, `next`).
 */
final class SyncPaginationHttpTest extends HttpTestCase
{
    public function testServerCappedPagesAreFullyFetchedAndNotReaped(): void
    {
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, 'aPage25');
        $this->seedUser('admin', 'admin@test.local', 'secret123');
        $jar = $this->login('admin@test.local', 'secret123');

        // Sync COMPLETO: 25 envíos servidos en páginas de 10 → deben llegar TODOS.
        $res = $this->request('POST', "forms/$formId/sync?full=1", [], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(25, $res['json']['data']['submissions']);
        $this->assertSame(0, $res['json']['data']['removed']);

        $cached = (int) DB::run(
            'SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ?', [$formId]
        )->fetch()['c'];
        $this->assertSame(25, $cached);

        // Sync INCREMENTAL posterior: el barrido de bajas pide los _id vivos con la
        // misma paginación topada — no debe «retirar» nada (antes del fix, todo lo
        // que quedara fuera de la primera página se daba por borrado en Kobo).
        $res2 = $this->request('POST', "forms/$formId/sync", [], $jar);
        $this->assertSame(200, $res2['status'], $res2['raw']);
        $this->assertSame(0, $res2['json']['data']['removed']);

        $cached2 = (int) DB::run(
            'SELECT COUNT(*) AS c FROM submissions_cache WHERE form_id = ?', [$formId]
        )->fetch()['c'];
        $this->assertSame(25, $cached2);
        @unlink($jar);
    }
}
