<?php

declare(strict_types=1);

require_once __DIR__ . '/DbTestCase.php';

/** KoboClient falso: devuelve un mapa fijo de _uuid → validation_status.uid. */
final class FakeValidationClient extends KoboClient
{
    /** @var array<string,string> */
    public array $map;

    public function __construct(array $map)
    {
        parent::__construct('https://example.invalid', 'token');
        $this->map = $map;
    }

    public function getValidationStatuses(string $assetUid, int $pageSize = 10000, int $maxPages = 100): array
    {
        return $this->map;
    }
}

/**
 * Pull del estado de validación de Kobo (SubmissionSync::reconcileValidation):
 * merge a 3 vías koboNow / baseline / localNow, con «gana Kobo» en conflicto.
 */
final class SyncValidationTest extends DbTestCase
{
    private int $formId;
    private string $assetUid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formId   = $this->makeForm();
        $this->assetUid = DB::run('SELECT kobo_asset_uid FROM forms WHERE id = ?', [$this->formId])->fetch()['kobo_asset_uid'];
    }

    private function seedCache(string $uid, ?string $seen = null): void
    {
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, kobo_validation_seen, submitted_at, last_synced_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$this->formId, $uid, json_encode(['_id' => 1, '_uuid' => $uid]), $seen, '2024-01-01 10:00:00']
        );
    }

    private function review(string $uid, string $status, string $source = 'app'): void
    {
        $userId = $source === 'app' ? $this->makeUser() : null;
        DB::run(
            'INSERT INTO submission_reviews (submission_uid, user_id, source, status) VALUES (?, ?, ?, ?)',
            [$uid, $userId, $source, $status]
        );
    }

    private function reconcile(array $koboMap): int
    {
        $m = new ReflectionMethod(SubmissionSync::class, 'reconcileValidation');
        return (int) $m->invoke(null, $this->formId, new FakeValidationClient($koboMap), $this->assetUid);
    }

    private function latestStatus(string $uid): string
    {
        $r = DB::run(
            'SELECT status FROM submission_reviews WHERE submission_uid = ? ORDER BY id DESC LIMIT 1',
            [$uid]
        )->fetch();
        return $r['status'] ?? 'pending';
    }

    private function seenUid(string $uid): ?string
    {
        return DB::run('SELECT kobo_validation_seen FROM submissions_cache WHERE submission_uid = ?', [$uid])->fetch()['kobo_validation_seen'];
    }

    public function testExternalApprovalPulledAndIdempotent(): void
    {
        $this->seedCache('s1'); // sin revisión, sin línea base
        $created = $this->reconcile(['s1' => 'validation_status_approved']);

        $this->assertSame(1, $created);
        $this->assertSame('approved', $this->latestStatus('s1'));
        $this->assertSame('validation_status_approved', $this->seenUid('s1'));

        // Idempotente: un segundo sync sin cambios en Kobo no crea otra fila.
        $this->assertSame(0, $this->reconcile(['s1' => 'validation_status_approved']));
        $this->assertSame(1, (int) DB::run('SELECT COUNT(*) c FROM submission_reviews WHERE submission_uid = ?', ['s1'])->fetch()['c']);
    }

    public function testAgreementUpdatesBaselineWithoutSyntheticRow(): void
    {
        // Local ya 'approved' (hecho en la app) pero la línea base aún NULL; Kobo también
        // 'approved' → no debe duplicarse: solo se fija la base.
        $this->seedCache('s2');
        $this->review('s2', 'approved', 'app');

        $created = $this->reconcile(['s2' => 'validation_status_approved']);
        $this->assertSame(0, $created);
        $this->assertSame('validation_status_approved', $this->seenUid('s2'));
        $this->assertSame(1, (int) DB::run('SELECT COUNT(*) c FROM submission_reviews WHERE submission_uid = ?', ['s2'])->fetch()['c']);
    }

    public function testExternalClearWinsOverLocal(): void
    {
        // Local 'approved', base 'approved'; en Kobo se limpió el estado → gana Kobo:
        // se inserta una revisión sintética 'pending' (source='kobo') y la base pasa a ''.
        $this->seedCache('s3', 'validation_status_approved');
        $this->review('s3', 'approved', 'app');

        $created = $this->reconcile(['s3' => '']);
        $this->assertSame(1, $created);
        $this->assertSame('pending', $this->latestStatus('s3'));
        $this->assertSame('', $this->seenUid('s3'));

        $kobo = DB::run("SELECT source, user_id FROM submission_reviews WHERE submission_uid = ? ORDER BY id DESC LIMIT 1", ['s3'])->fetch();
        $this->assertSame('kobo', $kobo['source']);
        $this->assertNull($kobo['user_id']);
    }

    public function testSubmissionAbsentFromKoboUntouched(): void
    {
        $this->seedCache('s4', 'validation_status_on_hold');
        $created = $this->reconcile([]); // s4 no está en el mapa de Kobo
        $this->assertSame(0, $created);
        $this->assertSame('validation_status_on_hold', $this->seenUid('s4'));
        $this->assertFalse((bool) DB::run('SELECT 1 FROM submission_reviews WHERE submission_uid = ?', ['s4'])->fetch());
    }
}
