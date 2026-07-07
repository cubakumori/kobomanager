<?php

declare(strict_types=1);

require_once __DIR__ . '/DbTestCase.php';

/** KoboClient falso: lista fija de _id vivos para el barrido de bajas. */
final class FakeIdsClient extends KoboClient
{
    /** @var int[] */
    public array $ids;

    public function __construct(array $ids)
    {
        parent::__construct('https://example.invalid', 'token');
        $this->ids = $ids;
    }

    public function getAllSubmissionIds(string $assetUid, int $pageSize = 10000, int $maxPages = 100): array
    {
        return $this->ids;
    }
}

/**
 * Barridos de bajas del sync (reconcileDeletions / reconcileFull): borran lo que ya
 * no existe en Kobo, pero con guardia anti-vaciado — una lista viva vacía frente a
 * una caché poblada se trata como fallo aguas arriba, no como borrado masivo.
 */
final class SyncReconcileTest extends DbTestCase
{
    private int $formId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formId = $this->makeForm();
    }

    private function seedCache(string $uid, int $koboId): void
    {
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at, last_synced_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$this->formId, $uid, json_encode(['_id' => $koboId, '_uuid' => $uid]), '2024-01-01 10:00:00']
        );
    }

    private function cachedUids(): array
    {
        return array_column(
            DB::run('SELECT submission_uid FROM submissions_cache WHERE form_id = ? ORDER BY submission_uid', [$this->formId])->fetchAll(),
            'submission_uid'
        );
    }

    private function reconcileDeletions(array $liveIds): int
    {
        $m = new ReflectionMethod(SubmissionSync::class, 'reconcileDeletions');
        return (int) $m->invoke(null, $this->formId, new FakeIdsClient($liveIds), 'aXYZ');
    }

    private function reconcileFull(array $keepUids): int
    {
        $m = new ReflectionMethod(SubmissionSync::class, 'reconcileFull');
        return (int) $m->invoke(null, $this->formId, $keepUids);
    }

    public function testIncrementalSweepDropsOnlyDeadIds(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        $this->assertSame(1, $this->reconcileDeletions([1]));
        $this->assertSame(['u1'], $this->cachedUids());
    }

    public function testIncrementalSweepRefusesToWipeOnEmptyLiveList(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        // Lista viva vacía + caché poblada → no se borra nada.
        $this->assertSame(0, $this->reconcileDeletions([]));
        $this->assertSame(['u1', 'u2'], $this->cachedUids());
    }

    public function testFullSweepDropsOnlyMissingUids(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        $this->assertSame(1, $this->reconcileFull(['u1']));
        $this->assertSame(['u1'], $this->cachedUids());
    }

    public function testFullSweepRefusesToWipeOnEmptyKeepList(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        $this->assertSame(0, $this->reconcileFull([]));
        $this->assertSame(['u1', 'u2'], $this->cachedUids());
    }
}
