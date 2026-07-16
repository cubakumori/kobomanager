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
 * no existe en Kobo, pero con guardia anti-vaciado — una lista viva vacía frente a una
 * caché poblada se trata como fallo aguas arriba, no como borrado masivo: no se borra
 * nada de oficio y se anuncia `wipe_available` (+ `cached`) para que el sync MANUAL
 * ofrezca la confirmación; solo con `$confirmWipe` (jamás desde el cron) se vacía.
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
        // kobo_id materializado, como lo escribe el sync (el barrido de bajas lee la columna).
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, kobo_id, submitted_at, last_synced_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$this->formId, $uid, json_encode(['_id' => $koboId, '_uuid' => $uid]), $koboId, '2024-01-01 10:00:00']
        );
    }

    private function cachedUids(): array
    {
        return array_column(
            DB::run('SELECT submission_uid FROM submissions_cache WHERE form_id = ? ORDER BY submission_uid', [$this->formId])->fetchAll(),
            'submission_uid'
        );
    }

    private function reconcileDeletions(array $liveIds, bool $confirmWipe = false): array
    {
        $m = new ReflectionMethod(SubmissionSync::class, 'reconcileDeletions');
        return $m->invoke(null, $this->formId, new FakeIdsClient($liveIds), 'aXYZ', $confirmWipe);
    }

    private function reconcileFull(array $keepUids, bool $confirmWipe = false): array
    {
        $m = new ReflectionMethod(SubmissionSync::class, 'reconcileFull');
        return $m->invoke(null, $this->formId, $keepUids, $confirmWipe);
    }

    public function testIncrementalSweepDropsOnlyDeadIds(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        $this->assertSame(1, $this->reconcileDeletions([1])['removed']);
        $this->assertSame(['u1'], $this->cachedUids());
    }

    public function testIncrementalSweepRefusesToWipeOnEmptyLiveList(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        // Lista viva vacía + caché poblada → no se borra nada; se ofrece el vaciado.
        $res = $this->reconcileDeletions([]);
        $this->assertSame(0, $res['removed']);
        $this->assertTrue($res['wipe_available']);
        $this->assertSame(2, $res['cached']);
        $this->assertSame(['u1', 'u2'], $this->cachedUids());
    }

    public function testIncrementalSweepWipesWithConfirmation(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        $res = $this->reconcileDeletions([], true);
        $this->assertSame(2, $res['removed']);
        $this->assertTrue($res['wiped']);
        $this->assertSame([], $this->cachedUids());
    }

    public function testConfirmationIsHarmlessWithLiveSubmissions(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        // Si al confirmar Kobo YA devuelve vivos (llegó un envío entre medias), la
        // confirmación no vacía nada: barrido normal.
        $res = $this->reconcileDeletions([1], true);
        $this->assertSame(1, $res['removed']);
        $this->assertArrayNotHasKey('wiped', $res);
        $this->assertSame(['u1'], $this->cachedUids());
    }

    public function testFullSweepDropsOnlyMissingUids(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        $this->assertSame(1, $this->reconcileFull(['u1'])['removed']);
        $this->assertSame(['u1'], $this->cachedUids());
    }

    public function testFullSweepRefusesToWipeOnEmptyKeepList(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        $res = $this->reconcileFull([]);
        $this->assertSame(0, $res['removed']);
        $this->assertTrue($res['wipe_available']);
        $this->assertSame(2, $res['cached']);
        $this->assertSame(['u1', 'u2'], $this->cachedUids());
    }

    public function testFullSweepWipesWithConfirmation(): void
    {
        $this->seedCache('u1', 1);
        $this->seedCache('u2', 2);

        $res = $this->reconcileFull([], true);
        $this->assertSame(2, $res['removed']);
        $this->assertTrue($res['wiped']);
        $this->assertSame([], $this->cachedUids());
    }
}
