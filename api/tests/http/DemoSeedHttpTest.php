<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP de la semilla de la demo con la demo APAGADA (config normal):
 * `POST /admin/demo/seed` genera la semilla (solo admin) y `GET /admin/settings`
 * expone su estado para la tarjeta de la UI. Además, smoke test del cron
 * `cron/demo_reset.php` por CLI: con DEMO_MODE apagado se niega a restaurar; con
 * la config de demo restaura, estampa el ciclo y el gate omite la segunda pasada.
 */
final class DemoSeedHttpTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(DEMO_SEED_PATH);
    }

    protected function tearDown(): void
    {
        @unlink(DEMO_SEED_PATH);
        parent::tearDown();
    }

    public function testAdminGeneratesSeedAndSettingsExposeStatus(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar = $this->login('admin@test.local', 'Secret123!');

        // Antes de generar: configurada pero sin archivo.
        $res = $this->request('GET', 'admin/settings', null, $jar);
        $this->assertSame(200, $res['status']);
        $seed = $res['json']['data']['demo_seed'];
        $this->assertTrue($seed['configured']);
        $this->assertFalse($seed['exists']);

        $res = $this->request('POST', 'admin/demo/seed', [], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertGreaterThan(0, $res['json']['data']['rows']);
        $this->assertFileExists(DEMO_SEED_PATH);
        $this->assertTrue($res['json']['data']['status']['exists']);

        // Queda rastro en la auditoría.
        $n = DB::run("SELECT COUNT(*) AS n FROM audit_log WHERE action = 'generate_demo_seed'")->fetch()['n'];
        $this->assertSame(1, (int) $n);
    }

    public function testViewerCannotGenerateSeed(): void
    {
        $this->seedUser('viewer', 'viewer@test.local', 'Secret123!');
        $jar = $this->login('viewer@test.local', 'Secret123!');

        $res = $this->request('POST', 'admin/demo/seed', [], $jar);
        $this->assertSame(403, $res['status']);
        $this->assertSame('AUTH_INSUFFICIENT_PERMISSIONS', $res['json']['error']['code']);
        $this->assertFileDoesNotExist(DEMO_SEED_PATH);
    }

    public function testResetCronRefusesWithDemoOffAndRestoresWithDemoOn(): void
    {
        $adminId = $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar = $this->login('admin@test.local', 'Secret123!');
        $res = $this->request('POST', 'admin/demo/seed', [], $jar);
        $this->assertSame(200, $res['status'], $res['raw']);

        $apiDir = dirname(__DIR__, 2);
        $cmd = static fn(string $config, string $extra = ''): string => sprintf(
            'KM_CONFIG=%s php %s %s 2>&1',
            escapeshellarg(dirname(__DIR__) . '/' . $config),
            escapeshellarg($apiDir . '/cron/demo_reset.php'),
            $extra
        );

        // DEMO_MODE apagado (config normal): el cron se niega, la BD no se toca.
        DB::run('UPDATE users SET name = ? WHERE id = ?', ['Sucio', $adminId]);
        exec($cmd('config.http.php', '--force'), $outOff, $rcOff);
        $this->assertSame(0, $rcOff);
        $this->assertStringContainsString('DEMO_MODE está apagado', implode("\n", $outOff));
        $this->assertSame('Sucio', DB::run('SELECT name FROM users WHERE id = ?', [$adminId])->fetch()['name']);

        // DEMO_MODE encendido (config de demo, misma BD): restaura y estampa el ciclo.
        exec($cmd('config.http.demo.php', '--force'), $outOn, $rcOn);
        $this->assertSame(0, $rcOn, implode("\n", $outOn));
        $this->assertStringContainsString('demo restaurada', implode("\n", $outOn));
        $this->assertSame('Test admin', DB::run('SELECT name FROM users WHERE id = ?', [$adminId])->fetch()['name']);
        $this->assertGreaterThan(0, (int) Settings::get('demo_last_reset_at', 0));
        $runs = Settings::cronRuns();
        $this->assertTrue($runs['demo_reset']['ok'] ?? false);

        // Gate: el ciclo (45 min en la config de demo) aún no venció → segunda pasada
        // SIN --force sale en silencio y no restaura.
        DB::run('UPDATE users SET name = ? WHERE id = ?', ['Sucio otra vez', $adminId]);
        exec($cmd('config.http.demo.php'), $outGate, $rcGate);
        $this->assertSame(0, $rcGate);
        $this->assertSame('', trim(implode("\n", $outGate)));
        $this->assertSame('Sucio otra vez', DB::run('SELECT name FROM users WHERE id = ?', [$adminId])->fetch()['name']);
    }
}
