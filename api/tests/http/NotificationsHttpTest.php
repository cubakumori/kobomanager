<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP de las preferencias de aviso por email (notification_config.frequency)
 * y del «por defecto» global (notifications_default_frequency): efectiva = preferencia
 * explícita del usuario o, en su ausencia, el valor por defecto. Una elección explícita
 * persiste aunque cambie el default. También cubre la marca de agua de los avisos casi
 * inmediatos: se ancla al activar una frecuencia viva y se conserva al re-guardar.
 */
final class NotificationsHttpTest extends HttpTestCase
{
    private function setDefaultFrequency(string $freq): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar = $this->login('admin@test.local', 'Secret123!');
        $res = $this->request('PUT', 'admin/settings', ['notifications_default_frequency' => $freq], $jar);
        $this->assertSame(200, $res['status']);
        @unlink($jar);
    }

    public function testDefaultOffMeansNoAlertsUntilOptIn(): void
    {
        $uid    = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $formId = $this->seedForm($this->seedAccount());
        $this->grant($uid, $formId, view: true);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Sin preferencia y default off → sin avisos.
        $get = $this->request('GET', 'notifications', null, $jar);
        $this->assertSame(200, $get['status']);
        $this->assertSame('off', $get['json']['data']['default_frequency']);
        $this->assertSame('off', $get['json']['data']['forms'][0]['frequency']);

        // Opt-in explícito a 'daily'.
        $this->request('PUT', 'notifications', ['frequencies' => [$formId => 'daily']], $jar);
        $get = $this->request('GET', 'notifications', null, $jar);
        $this->assertSame('daily', $get['json']['data']['forms'][0]['frequency']);
        @unlink($jar);
    }

    public function testDefaultFrequencyAppliesAndExplicitChoicePersists(): void
    {
        $this->setDefaultFrequency('daily');

        $uid    = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $formId = $this->seedForm($this->seedAccount());
        $this->grant($uid, $formId, view: true);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Sin preferencia explícita pero default 'daily' → efectiva 'daily'.
        $get = $this->request('GET', 'notifications', null, $jar);
        $this->assertSame('daily', $get['json']['data']['default_frequency']);
        $this->assertSame('daily', $get['json']['data']['forms'][0]['frequency']);

        // Opt-out explícito ('off') → se guarda y persiste pese al default.
        $this->request('PUT', 'notifications', ['frequencies' => [$formId => 'off']], $jar);
        $get = $this->request('GET', 'notifications', null, $jar);
        $this->assertSame('off', $get['json']['data']['forms'][0]['frequency']);

        $row = DB::run('SELECT frequency FROM notification_config WHERE user_id = ? AND form_id = ?', [$uid, $formId])->fetch();
        $this->assertSame('off', $row['frequency']);
        @unlink($jar);
    }

    public function testLiveFrequencyAnchorsWatermarkAndResavePreservesIt(): void
    {
        $uid    = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $formId = $this->seedForm($this->seedAccount());
        $this->grant($uid, $formId, view: true);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Al pasar a una frecuencia viva, la marca de agua se ancla a «ahora».
        $this->request('PUT', 'notifications', ['frequencies' => [$formId => 'every_sync']], $jar);
        $row = DB::run('SELECT last_notified_at FROM notification_config WHERE user_id = ? AND form_id = ?', [$uid, $formId])->fetch();
        $this->assertNotNull($row['last_notified_at']);
        $anchored = $row['last_notified_at'];

        // Retrasarla artificialmente y re-guardar la MISMA frecuencia viva: se conserva
        // (guardar preferencias no debe reiniciar la ventana de aviso).
        DB::run('UPDATE notification_config SET last_notified_at = ? WHERE user_id = ? AND form_id = ?',
            ['2020-01-01 00:00:00', $uid, $formId]);
        $this->request('PUT', 'notifications', ['frequencies' => [$formId => 'hourly']], $jar);
        $row = DB::run('SELECT frequency, last_notified_at FROM notification_config WHERE user_id = ? AND form_id = ?', [$uid, $formId])->fetch();
        $this->assertSame('hourly', $row['frequency']);
        $this->assertSame('2020-01-01 00:00:00', $row['last_notified_at']);

        // Salir de una frecuencia viva y volver a entrar: se re-ancla (no se avisa del hueco).
        $this->request('PUT', 'notifications', ['frequencies' => [$formId => 'off']], $jar);
        $this->request('PUT', 'notifications', ['frequencies' => [$formId => 'every_sync']], $jar);
        $row = DB::run('SELECT last_notified_at FROM notification_config WHERE user_id = ? AND form_id = ?', [$uid, $formId])->fetch();
        $this->assertNotSame('2020-01-01 00:00:00', $row['last_notified_at']);
        $this->assertGreaterThanOrEqual($anchored, $row['last_notified_at']);
        @unlink($jar);
    }

    public function testRejectsInvalidFrequency(): void
    {
        $uid    = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $formId = $this->seedForm($this->seedAccount());
        $this->grant($uid, $formId, view: true);
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('PUT', 'notifications', ['frequencies' => [$formId => 'weekly']], $jar);
        $this->assertSame(422, $res['status']);
        @unlink($jar);
    }

    public function testAdminSettingsValidateQuietHours(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar = $this->login('admin@test.local', 'Secret123!');

        // Tramo válido (cruza la medianoche): se guarda y se devuelve.
        $res = $this->request('PUT', 'admin/settings',
            ['notifications_quiet' => ['start' => '22:00', 'end' => '07:00']], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(['start' => '22:00', 'end' => '07:00'], $res['json']['data']['notifications_quiet']);

        $get = $this->request('GET', 'admin/settings', null, $jar);
        $this->assertSame(['start' => '22:00', 'end' => '07:00'], $get['json']['data']['notifications_quiet']);

        // Hora mal formada → 422; inicio = fin → 422; null lo quita.
        $bad = $this->request('PUT', 'admin/settings', ['notifications_quiet' => ['start' => '25:00', 'end' => '07:00']], $jar);
        $this->assertSame(422, $bad['status']);
        $same = $this->request('PUT', 'admin/settings', ['notifications_quiet' => ['start' => '07:00', 'end' => '07:00']], $jar);
        $this->assertSame(422, $same['status']);
        $off = $this->request('PUT', 'admin/settings', ['notifications_quiet' => null], $jar);
        $this->assertSame(200, $off['status']);
        $this->assertNull($off['json']['data']['notifications_quiet']);
        @unlink($jar);
    }
}
