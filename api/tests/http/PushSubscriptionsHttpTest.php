<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del alta/baja de suscripciones Web Push (/push/subscriptions):
 * opt-in por dispositivo, upsert por endpoint, validación de claves y aislamiento
 * entre usuarios. Las claves VAPID de test viven en tests/config.http.php.
 */
final class PushSubscriptionsHttpTest extends HttpTestCase
{
    // Claves de suscripción con el formato correcto (las del vector RFC 8291 §5).
    private const P256DH = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const AUTH   = 'BTBZMqHH6r4Tts7J_aSIgg';

    private function subscribeBody(string $endpoint, string $label = 'Chrome · macOS'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => self::P256DH, 'auth' => self::AUTH], 'label' => $label];
    }

    public function testSubscribeListAndUnsubscribe(): void
    {
        $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $jar = $this->login('v@test.local', 'Secret123!');

        // Alta.
        $endpoint = 'https://push.example/device-1';
        $res = $this->request('POST', 'push/subscriptions', $this->subscribeBody($endpoint), $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(hash('sha256', $endpoint), $res['json']['data']['endpoint_hash']);

        // Lista: aparece con su etiqueta y sin exponer claves ni endpoint en claro.
        $get = $this->request('GET', 'push/subscriptions', null, $jar);
        $this->assertTrue($get['json']['data']['configured']);
        $this->assertCount(1, $get['json']['data']['subscriptions']);
        $sub = $get['json']['data']['subscriptions'][0];
        $this->assertSame('Chrome · macOS', $sub['label']);
        $this->assertArrayNotHasKey('p256dh', $sub);
        $this->assertArrayNotHasKey('endpoint', $sub);

        // Re-suscribir el mismo endpoint (claves rotadas) = upsert, no duplica.
        $this->request('POST', 'push/subscriptions', $this->subscribeBody($endpoint, 'Chrome · macOS (2)'), $jar);
        $get = $this->request('GET', 'push/subscriptions', null, $jar);
        $this->assertCount(1, $get['json']['data']['subscriptions']);
        $this->assertSame('Chrome · macOS (2)', $get['json']['data']['subscriptions'][0]['label']);

        // Baja por endpoint (el propio dispositivo).
        $del = $this->request('DELETE', 'push/subscriptions', ['endpoint' => $endpoint], $jar);
        $this->assertSame(200, $del['status']);
        $get = $this->request('GET', 'push/subscriptions', null, $jar);
        $this->assertCount(0, $get['json']['data']['subscriptions']);
        @unlink($jar);
    }

    public function testRejectsMalformedSubscription(): void
    {
        $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $jar = $this->login('v@test.local', 'Secret123!');

        // Endpoint no-https.
        $bad = $this->subscribeBody('http://insecure.example/x');
        $this->assertSame(422, $this->request('POST', 'push/subscriptions', $bad, $jar)['status']);

        // Claves con tamaño incorrecto.
        $bad = $this->subscribeBody('https://push.example/x');
        $bad['keys']['p256dh'] = 'AAAA';
        $this->assertSame(422, $this->request('POST', 'push/subscriptions', $bad, $jar)['status']);
        @unlink($jar);
    }

    public function testUsersOnlySeeAndDeleteTheirOwnDevices(): void
    {
        $u1 = $this->seedUser('viewer', 'a@test.local', 'Secret123!');
        $u2 = $this->seedUser('viewer', 'b@test.local', 'Secret123!');
        $jarA = $this->login('a@test.local', 'Secret123!');
        $jarB = $this->login('b@test.local', 'Secret123!');

        $this->request('POST', 'push/subscriptions', $this->subscribeBody('https://push.example/of-a'), $jarA);
        $getB = $this->request('GET', 'push/subscriptions', null, $jarB);
        $this->assertCount(0, $getB['json']['data']['subscriptions']);

        // B no puede borrar el dispositivo de A por id.
        $idA = (int) DB::run('SELECT id FROM push_subscriptions WHERE user_id = ?', [$u1])->fetch()['id'];
        $this->request('DELETE', 'push/subscriptions', ['id' => $idA], $jarB);
        $left = (int) DB::run('SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?', [$u1])->fetch()['c'];
        $this->assertSame(1, $left);
        @unlink($jarA);
        @unlink($jarB);
    }

    public function testConfigExposesPublicKey(): void
    {
        $res = $this->request('GET', 'config');
        $this->assertSame(200, $res['status']);
        $this->assertSame(VAPID_PUBLIC_KEY, $res['json']['data']['push_public_key']);
    }
}
