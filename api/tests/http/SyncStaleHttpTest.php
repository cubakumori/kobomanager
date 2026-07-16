<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP de POST /forms/sync-stale (ajuste global `sync_on_login`):
 * sincroniza los formularios VISIBLES del usuario con envíos de >10 min de
 * antigüedad. Con el ajuste apagado es un no-op; los formularios frescos se
 * saltan; un viewer solo alcanza sus formularios con can_view.
 */
final class SyncStaleHttpTest extends HttpTestCase
{
    private function syncedAt(int $formId): ?string
    {
        $v = DB::run('SELECT submissions_synced_at FROM forms WHERE id = ?', [$formId])->fetchColumn();
        return $v === null || $v === false ? null : (string) $v;
    }

    public function testNoOpWhenSettingOff(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'secret123');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId); // submissions_synced_at NULL = obsoleto
        $jar    = $this->login('admin@test.local', 'secret123');

        $res = $this->request('POST', 'forms/sync-stale', [], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertFalse($res['json']['data']['enabled']);
        $this->assertNull($this->syncedAt($formId)); // no se tocó
        @unlink($jar);
    }

    public function testSyncsStaleAndSkipsFreshForms(): void
    {
        Settings::set('sync_on_login', true);
        $this->seedUser('admin', 'admin@test.local', 'secret123');
        $accId   = $this->seedAccount();
        $staleId = $this->seedForm($accId);
        $freshId = $this->seedForm($accId);
        DB::run('UPDATE forms SET submissions_synced_at = NOW() WHERE id = ?', [$freshId]);
        $freshBefore = $this->syncedAt($freshId);
        $jar = $this->login('admin@test.local', 'secret123');

        $res = $this->request('POST', 'forms/sync-stale', [], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $d = $res['json']['data'];
        $this->assertTrue($d['enabled']);
        $this->assertSame(1, $d['synced']);
        $this->assertSame(0, $d['errors']);
        $this->assertNotNull($this->syncedAt($staleId));   // el obsoleto se sincronizó
        $this->assertSame($freshBefore, $this->syncedAt($freshId)); // el fresco, no
        @unlink($jar);
    }

    public function testViewerOnlyReachesGrantedForms(): void
    {
        Settings::set('sync_on_login', true);
        $userId = $this->seedUser('viewer', 'v@test.local', 'secret123');
        $accId    = $this->seedAccount();
        $granted  = $this->seedForm($accId);
        $ungranted = $this->seedForm($accId);
        $this->grant($userId, $granted);
        $jar = $this->login('v@test.local', 'secret123');

        $res = $this->request('POST', 'forms/sync-stale', [], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(1, $res['json']['data']['synced']);
        $this->assertNotNull($this->syncedAt($granted));
        $this->assertNull($this->syncedAt($ungranted));
        @unlink($jar);
    }
}
