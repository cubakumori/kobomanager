<?php

declare(strict_types=1);

require_once __DIR__ . '/DbTestCase.php';

/**
 * Retención de envíos (forms.retention_days): la purga elimina de la caché lo más
 * viejo que la ventana JUNTO con su historial de revisión local, conserva lo que
 * está dentro (y lo sin fecha, por prudencia), y el barrido de reconciliación no
 * cuenta como «retirado» lo que el import saltó por estar fuera de ventana.
 */
final class SyncRetentionTest extends DbTestCase
{
    private int $formId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formId = $this->makeForm();
    }

    private function seedCache(string $uid, ?string $submittedAt): void
    {
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at, last_synced_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$this->formId, $uid, json_encode(['_uuid' => $uid]), $submittedAt]
        );
    }

    private function seedReview(string $uid): void
    {
        ValidationStatus::recordReview($uid, $this->makeUser('admin'), 'app', 'approved');
    }

    private function cachedUids(): array
    {
        return array_column(
            DB::run('SELECT submission_uid FROM submissions_cache WHERE form_id = ? ORDER BY submission_uid', [$this->formId])->fetchAll(),
            'submission_uid'
        );
    }

    public function testRetentionCutoff(): void
    {
        $this->assertNull(SubmissionSync::retentionCutoff(null));
        $this->assertNull(SubmissionSync::retentionCutoff(0));
        // 30 días: el corte cae (con margen de segundos) 30 días atrás en UTC.
        $cut = SubmissionSync::retentionCutoff(30);
        $this->assertEqualsWithDelta(time() - 30 * 86400, strtotime($cut . ' UTC'), 5);
    }

    public function testPurgeRemovesOldWithLocalHistoryAndKeepsTheRest(): void
    {
        $old   = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $fresh = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
        $this->seedCache('u_old', $old);
        $this->seedCache('u_new', $fresh);
        $this->seedCache('u_nodate', null); // sin fecha: se conserva por prudencia
        $this->seedReview('u_old');
        $this->seedReview('u_new');

        $purged = SubmissionSync::purgeExpired($this->formId, 30);

        $this->assertSame(1, $purged);
        $this->assertSame(['u_new', 'u_nodate'], array_values(array_diff($this->cachedUids(), [])));
        // El historial local del purgado se fue con él; el del vivo sigue.
        $this->assertSame(0, (int) DB::run(
            "SELECT COUNT(*) c FROM submission_reviews WHERE submission_uid = 'u_old'"
        )->fetch()['c']);
        $this->assertSame(1, (int) DB::run(
            "SELECT COUNT(*) c FROM submission_reviews WHERE submission_uid = 'u_new'"
        )->fetch()['c']);
        // El contador cacheado del formulario queda al día sin esperar a un sync.
        $this->assertSame(2, (int) DB::run(
            'SELECT submission_count FROM forms WHERE id = ?', [$this->formId]
        )->fetch()['submission_count']);
    }

    public function testPurgeWithoutRetentionIsANoop(): void
    {
        $this->seedCache('u1', gmdate('Y-m-d H:i:s', time() - 400 * 86400));

        $this->assertSame(0, SubmissionSync::purgeExpired($this->formId, null));
        $this->assertSame(0, SubmissionSync::purgeExpired($this->formId, 0));
        $this->assertSame(['u1'], $this->cachedUids());
    }

    public function testReconcileDoesNotCountWindowSkippedAsRemoved(): void
    {
        // Tras la purga, en caché solo queda lo de dentro de la ventana. El import
        // registra en seenUids TODO lo vivo en Kobo (también lo que salta por viejo),
        // así que el barrido completo no debe borrar ni contar nada como «retirado».
        $this->seedCache('u_new', gmdate('Y-m-d H:i:s', time() - 2 * 86400));

        $m = new ReflectionMethod(SubmissionSync::class, 'reconcileFull');
        $res = $m->invoke(null, $this->formId, ['u_old_skipped', 'u_new'], false);

        $this->assertSame(0, $res['removed']);
        $this->assertSame(['u_new'], $this->cachedUids());
    }
}
