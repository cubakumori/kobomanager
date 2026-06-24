<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del resumen diario por email (preferencias en notification_config)
 * y del «por defecto» global (notifications_default_on): efectivo = preferencia
 * explícita del usuario o, en su ausencia, el valor por defecto. Un opt-out explícito
 * persiste aunque el default esté activo.
 */
final class NotificationsHttpTest extends HttpTestCase
{
    private function setDefaultOn(bool $on): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar = $this->login('admin@test.local', 'Secret123!');
        $res = $this->request('PUT', 'admin/settings', ['notifications_default_on' => $on], $jar);
        $this->assertSame(200, $res['status']);
        @unlink($jar);
    }

    public function testDefaultOffMeansUnsubscribedUntilOptIn(): void
    {
        $uid    = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $formId = $this->seedForm($this->seedAccount());
        $this->grant($uid, $formId, view: true);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Sin preferencia y default off → no suscrito.
        $get = $this->request('GET', 'notifications', null, $jar);
        $this->assertSame(200, $get['status']);
        $this->assertFalse($get['json']['data']['default_on']);
        $this->assertFalse($get['json']['data']['forms'][0]['daily_summary']);

        // Opt-in explícito → suscrito.
        $this->request('PUT', 'notifications', ['enabled' => [$formId]], $jar);
        $get = $this->request('GET', 'notifications', null, $jar);
        $this->assertTrue($get['json']['data']['forms'][0]['daily_summary']);
        @unlink($jar);
    }

    public function testDefaultOnSubscribesVisibleFormsAndOptOutPersists(): void
    {
        $this->setDefaultOn(true);

        $uid    = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $formId = $this->seedForm($this->seedAccount());
        $this->grant($uid, $formId, view: true);
        $jar = $this->login('v@test.local', 'Secret123!');

        // Sin preferencia explícita pero default on → aparece suscrito.
        $get = $this->request('GET', 'notifications', null, $jar);
        $this->assertTrue($get['json']['data']['default_on']);
        $this->assertTrue($get['json']['data']['forms'][0]['daily_summary']);

        // Opt-out explícito (PUT con lista vacía) → se guarda 0 y persiste pese al default.
        $this->request('PUT', 'notifications', ['enabled' => []], $jar);
        $get = $this->request('GET', 'notifications', null, $jar);
        $this->assertFalse($get['json']['data']['forms'][0]['daily_summary']);

        $row = DB::run('SELECT daily_summary FROM notification_config WHERE user_id = ? AND form_id = ?', [$uid, $formId])->fetch();
        $this->assertSame(0, (int) $row['daily_summary']);
        @unlink($jar);
    }
}
