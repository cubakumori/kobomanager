<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: orden configurable de las tarjetas en «Mis formularios»
 * (ajuste global `forms_order`, lista blanca de ORDER BY en v1/forms/index.php).
 */
final class FormsOrderHttpTest extends HttpTestCase
{
    private function account(string $label): int
    {
        DB::run(
            'INSERT INTO kobo_accounts (label, server_url, email, api_token) VALUES (?, ?, ?, ?)',
            [$label, self::koboBase(), 'a@test.local', TokenVault::encrypt('t')]
        );
        return (int) DB::conn()->lastInsertId();
    }

    private function form(int $accId, string $name): int
    {
        DB::run(
            'INSERT INTO forms (kobo_account_id, kobo_asset_uid, name, server_url, active) VALUES (?, ?, ?, ?, 1)',
            [$accId, 'asset_' . bin2hex(random_bytes(4)), $name, 'https://eu.kobotoolbox.org']
        );
        return (int) DB::conn()->lastInsertId();
    }

    /** @return string[] nombres en el orden devuelto por GET /forms */
    private function orderedNames(string $jar): array
    {
        $res = $this->request('GET', 'forms', null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        return array_map(fn ($f) => $f['name'], $res['json']['data']);
    }

    public function testFormsOrderModes(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accA = $this->account('AAA-acc');
        $accB = $this->account('BBB-acc');
        $zzz  = $this->form($accA, 'ZZZ'); // cuenta A, nombre alto
        $aaa  = $this->form($accB, 'AAA'); // cuenta B, nombre bajo
        // Marcas explícitas para los modos por fecha (deterministas).
        DB::run('UPDATE forms SET submissions_synced_at = ?, created_at = ? WHERE id = ?', ['2024-01-01 00:00:00', '2024-01-01 00:00:00', $zzz]);
        DB::run('UPDATE forms SET submissions_synced_at = ?, created_at = ? WHERE id = ?', ['2024-02-01 00:00:00', '2024-02-01 00:00:00', $aaa]);

        $jar = $this->login('admin@test.local', 'Secret123!');

        // Por defecto (sin ajuste): cuenta + nombre → A antes que B.
        $this->assertSame(['ZZZ', 'AAA'], $this->orderedNames($jar));

        // Por nombre A→Z.
        $this->setSetting('forms_order', 'name');
        $this->assertSame(['AAA', 'ZZZ'], $this->orderedNames($jar));

        // Últimos sincronizados primero (AAA se sincronizó después).
        $this->setSetting('forms_order', 'recent_sync');
        $this->assertSame(['AAA', 'ZZZ'], $this->orderedNames($jar));

        // Añadidos más recientes primero (AAA se creó después).
        $this->setSetting('forms_order', 'recent_created');
        $this->assertSame(['AAA', 'ZZZ'], $this->orderedNames($jar));

        @unlink($jar);
    }
}
