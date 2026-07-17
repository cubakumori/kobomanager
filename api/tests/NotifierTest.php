<?php

declare(strict_types=1);

/**
 * Tests de lib/Notifier (avisos casi inmediatos de envíos nuevos).
 *
 * El transporte se inyecta (callable coleccionador), así que no se toca Resend. El
 * «ahora» también se inyecta para que el throttle y el silencio sean deterministas.
 * Cubre los guardarraíles del hito: marca de agua (línea base sin inundar, avance
 * solo tras enviar), scoping por filas, throttle hourly y horario de silencio.
 */
final class NotifierTest extends DbTestCase
{
    /** @var array<int,array{to:string,subject:string,html:string,text:string}> */
    private array $outbox = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Estado conocido dentro de la transacción del test: la BD de test puede
        // arrastrar ajustes de notificación de corridas HTTP anteriores (que sí comitean).
        DB::run("DELETE FROM settings WHERE `key` IN
                 ('notifications_default_on', 'notifications_default_frequency',
                  'notifications_quiet_start', 'notifications_quiet_end')");
        // Y sin candidatos heredados: solo los usuarios que cree cada test.
        DB::run('UPDATE users SET active = 0');
        $this->outbox = [];
    }

    private function collector(bool $ok = true): callable
    {
        return function (string $to, string $subject, string $html, string $text) use ($ok): bool {
            $this->outbox[] = compact('to', 'subject', 'html', 'text');
            return $ok;
        };
    }

    private function now(string $utc = '2026-07-17 12:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    private function grantView(int $userId, int $formId, ?string $rowFilter = null): void
    {
        DB::run(
            'INSERT INTO user_form_permissions (user_id, form_id, can_view, row_filter) VALUES (?, ?, 1, ?)',
            [$userId, $formId, $rowFilter]
        );
    }

    private function subscribe(int $userId, int $formId, string $freq, ?string $watermark): void
    {
        DB::run(
            'INSERT INTO notification_config (user_id, form_id, frequency, last_notified_at) VALUES (?, ?, ?, ?)',
            [$userId, $formId, $freq, $watermark]
        );
    }

    private function addSubmission(int $formId, string $submittedAt, array $payload = []): void
    {
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, ?)',
            [$formId, 'uid_' . bin2hex(random_bytes(6)), json_encode((object) $payload), $submittedAt]
        );
    }

    private function watermark(int $userId, int $formId): ?string
    {
        $row = DB::run(
            'SELECT last_notified_at FROM notification_config WHERE user_id = ? AND form_id = ?',
            [$userId, $formId]
        )->fetch();
        return $row ? $row['last_notified_at'] : null;
    }

    public function testEverySyncSendsGroupedCountAndAdvancesWatermark(): void
    {
        $uid = $this->makeUser('viewer', true, 'v@test.local');
        $f1  = $this->makeForm();
        $f2  = $this->makeForm();
        $this->grantView($uid, $f1);
        $this->grantView($uid, $f2);
        $this->subscribe($uid, $f1, 'every_sync', '2026-07-17 11:00:00');
        $this->subscribe($uid, $f2, 'every_sync', '2026-07-17 11:00:00');

        $this->addSubmission($f1, '2026-07-17 11:30:00');
        $this->addSubmission($f1, '2026-07-17 11:45:00');
        $this->addSubmission($f2, '2026-07-17 11:50:00');
        $this->addSubmission($f1, '2026-07-17 10:00:00'); // anterior a la marca: no cuenta

        $res = Notifier::run($this->now(), $this->collector());

        $this->assertSame(1, $res['sent']);
        $this->assertCount(1, $this->outbox);
        $this->assertSame('v@test.local', $this->outbox[0]['to']);
        // Recuento agrupado (2 + 1 = 3) en el asunto; el texto lista ambos formularios.
        $this->assertStringContainsString('3', $this->outbox[0]['subject']);
        // Solo recuento + enlace: el cuerpo nunca lleva contenido del envío.
        $this->assertStringContainsString('/forms', $this->outbox[0]['text']);

        // La marca de agua avanza a «ahora» en ambos formularios.
        $this->assertSame('2026-07-17 12:00:00', $this->watermark($uid, $f1));
        $this->assertSame('2026-07-17 12:00:00', $this->watermark($uid, $f2));

        // Segunda pasada sin envíos nuevos: silencio.
        $res2 = Notifier::run($this->now('2026-07-17 12:15:00'), $this->collector());
        $this->assertSame(1, count($this->outbox));
        $this->assertSame(0, $res2['sent']);
    }

    public function testRespectsRowScope(): void
    {
        $uid = $this->makeUser('viewer', true, 'scoped@test.local');
        $f   = $this->makeForm();
        $this->grantView($uid, $f, json_encode([
            'match'  => 'all',
            'groups' => [['match' => 'all', 'conditions' => [['field' => 'team', 'op' => 'in', 'values' => ['A']]]]],
        ]));
        $this->subscribe($uid, $f, 'every_sync', '2026-07-17 11:00:00');

        $this->addSubmission($f, '2026-07-17 11:30:00', ['team' => 'A']);
        $this->addSubmission($f, '2026-07-17 11:31:00', ['team' => 'B']); // fuera de alcance
        $this->addSubmission($f, '2026-07-17 11:32:00', ['team' => 'B']); // fuera de alcance

        Notifier::run($this->now(), $this->collector());

        $this->assertCount(1, $this->outbox);
        // Solo cuenta el envío en alcance (1, no 3).
        $this->assertStringContainsString('1 envío nuevo', $this->outbox[0]['text']);
        $this->assertStringNotContainsString('3', $this->outbox[0]['subject']);
    }

    public function testNullWatermarkBaselinesWithoutSending(): void
    {
        $uid = $this->makeUser('viewer', true, 'nuevo@test.local');
        $f   = $this->makeForm();
        $this->grantView($uid, $f);
        $this->subscribe($uid, $f, 'every_sync', null);
        $this->addSubmission($f, '2026-07-17 11:30:00'); // histórico previo a la línea base

        $res = Notifier::run($this->now(), $this->collector());

        // No inunda con el histórico: fija la línea base y no envía nada.
        $this->assertSame(0, $res['sent']);
        $this->assertSame(1, $res['baselined']);
        $this->assertCount(0, $this->outbox);
        $this->assertSame('2026-07-17 12:00:00', $this->watermark($uid, $f));

        // Lo posterior a la línea base sí se avisa en la siguiente pasada.
        $this->addSubmission($f, '2026-07-17 12:05:00');
        $res2 = Notifier::run($this->now('2026-07-17 12:15:00'), $this->collector());
        $this->assertSame(1, $res2['sent']);
        $this->assertCount(1, $this->outbox);
    }

    public function testDefaultFrequencySubscribesUsersWithoutRow(): void
    {
        Settings::set('notifications_default_frequency', 'every_sync');
        $uid = $this->makeUser('viewer', true, 'default@test.local');
        $f   = $this->makeForm();
        $this->grantView($uid, $f);
        // Sin fila en notification_config: hereda el default vivo.

        $res = Notifier::run($this->now(), $this->collector());
        // Primera pasada: crea la fila (frequency NULL = sigue heredando) como línea base.
        $this->assertSame(1, $res['baselined']);
        $this->assertSame(0, $res['sent']);
        $row = DB::run(
            'SELECT frequency, last_notified_at FROM notification_config WHERE user_id = ? AND form_id = ?',
            [$uid, $f]
        )->fetch();
        $this->assertNull($row['frequency']);
        $this->assertSame('2026-07-17 12:00:00', $row['last_notified_at']);

        $this->addSubmission($f, '2026-07-17 12:10:00');
        $res2 = Notifier::run($this->now('2026-07-17 12:15:00'), $this->collector());
        $this->assertSame(1, $res2['sent']);
    }

    public function testHourlyThrottleGroupsUntilDue(): void
    {
        $uid = $this->makeUser('viewer', true, 'hourly@test.local');
        $f   = $this->makeForm();
        $this->grantView($uid, $f);
        // Último aviso hace 30 min: aún no toca, aunque haya envíos nuevos.
        $this->subscribe($uid, $f, 'hourly', '2026-07-17 11:30:00');
        $this->addSubmission($f, '2026-07-17 11:45:00');

        $res = Notifier::run($this->now(), $this->collector());
        $this->assertSame(0, $res['sent']);
        $this->assertSame('2026-07-17 11:30:00', $this->watermark($uid, $f)); // no avanza

        // Pasada una hora: sale el acumulado agrupado.
        $this->addSubmission($f, '2026-07-17 12:20:00');
        $res2 = Notifier::run($this->now('2026-07-17 12:30:00'), $this->collector());
        $this->assertSame(1, $res2['sent']);
        $this->assertCount(1, $this->outbox);
        $this->assertStringContainsString('2 envíos nuevos', $this->outbox[0]['text']);
        $this->assertSame('2026-07-17 12:30:00', $this->watermark($uid, $f));
    }

    public function testQuietHoursSkipsRunAndKeepsWatermark(): void
    {
        Settings::set('notifications_quiet_start', '22:00');
        Settings::set('notifications_quiet_end', '07:00');

        $uid = $this->makeUser('viewer', true, 'quiet@test.local');
        $f   = $this->makeForm();
        $this->grantView($uid, $f);
        $this->subscribe($uid, $f, 'every_sync', '2026-07-17 22:00:00');
        $this->addSubmission($f, '2026-07-17 23:30:00');

        // 23:45 UTC con APP_TIMEZONE=UTC (config de test): dentro del silencio.
        $res = Notifier::run($this->now('2026-07-17 23:45:00'), $this->collector());
        $this->assertSame('quiet_hours', $res['skipped'] ?? null);
        $this->assertCount(0, $this->outbox);
        $this->assertSame('2026-07-17 22:00:00', $this->watermark($uid, $f));

        // Al terminar el silencio sale lo acumulado.
        $res2 = Notifier::run($this->now('2026-07-18 07:15:00'), $this->collector());
        $this->assertSame(1, $res2['sent']);
    }

    public function testFailedSendDoesNotAdvanceWatermark(): void
    {
        $uid = $this->makeUser('viewer', true, 'fail@test.local');
        $f   = $this->makeForm();
        $this->grantView($uid, $f);
        $this->subscribe($uid, $f, 'every_sync', '2026-07-17 11:00:00');
        $this->addSubmission($f, '2026-07-17 11:30:00');

        $res = Notifier::run($this->now(), $this->collector(ok: false));
        $this->assertSame(0, $res['sent']);
        $this->assertSame(1, $res['errors']);
        // Sin avance: el siguiente intento reagrupa lo mismo.
        $this->assertSame('2026-07-17 11:00:00', $this->watermark($uid, $f));
    }

    public function testAdminsAreNotifiedWithoutRowScope(): void
    {
        $uid = $this->makeUser('admin', true, 'boss@test.local');
        $f   = $this->makeForm();
        $this->subscribe($uid, $f, 'every_sync', '2026-07-17 11:00:00');
        $this->addSubmission($f, '2026-07-17 11:30:00', ['team' => 'B']);

        $res = Notifier::run($this->now(), $this->collector());
        $this->assertSame(1, $res['sent']);
        $this->assertSame('boss@test.local', $this->outbox[0]['to']);
    }

    public function testInQuietHoursHandlesMidnightAndTimezone(): void
    {
        $at = fn(string $utc) => new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        // Tramo que cruza la medianoche, en UTC.
        $this->assertTrue(Notifier::inQuietHours($at('2026-07-17 23:00:00'), '22:00', '07:00', 'UTC'));
        $this->assertTrue(Notifier::inQuietHours($at('2026-07-18 06:59:00'), '22:00', '07:00', 'UTC'));
        $this->assertFalse(Notifier::inQuietHours($at('2026-07-18 07:00:00'), '22:00', '07:00', 'UTC'));
        $this->assertFalse(Notifier::inQuietHours($at('2026-07-17 12:00:00'), '22:00', '07:00', 'UTC'));

        // Tramo simple sin cruzar medianoche.
        $this->assertTrue(Notifier::inQuietHours($at('2026-07-17 13:00:00'), '12:00', '14:00', 'UTC'));
        $this->assertFalse(Notifier::inQuietHours($at('2026-07-17 14:00:00'), '12:00', '14:00', 'UTC'));

        // El tramo se interpreta en la zona local: 23:30 UTC = 19:30 en UTC-4 (fuera).
        $this->assertFalse(Notifier::inQuietHours($at('2026-07-17 23:30:00'), '22:00', '07:00', 'America/Santo_Domingo'));
        // 02:30 UTC = 22:30 del día anterior en UTC-4 (dentro).
        $this->assertTrue(Notifier::inQuietHours($at('2026-07-18 02:30:00'), '22:00', '07:00', 'America/Santo_Domingo'));
    }

    public function testLegacyDefaultOnFallback(): void
    {
        // Partir de cero: la BD de test puede arrastrar estos ajustes de otras corridas.
        DB::run("DELETE FROM settings WHERE `key` IN ('notifications_default_on', 'notifications_default_frequency')");

        // Sin ajuste nuevo: el binario anterior true → 'daily', false/ausente → 'off'.
        $this->assertSame('off', Settings::notificationsDefaultFrequency());
        Settings::set('notifications_default_on', true);
        $this->assertSame('daily', Settings::notificationsDefaultFrequency());
        // El ajuste nuevo, cuando existe, manda.
        Settings::set('notifications_default_frequency', 'hourly');
        $this->assertSame('hourly', Settings::notificationsDefaultFrequency());
    }
}
