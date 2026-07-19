<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DbBackup — copia de seguridad/restauración sobre el motor DbSnapshot (ida y
 * vuelta real contra la BD de test). Igual que DemoSeedTest, no extiende
 * DbTestCase porque el restore gestiona su PROPIA transacción: limpia con
 * TRUNCATE + commit.
 */
final class DbBackupTest extends TestCase
{
    private const WORK_TABLES = [
        'audit_log', 'submission_reviews', 'submissions_cache', 'user_form_permissions',
        'user_form_favorites', 'sample_targets', 'sample_target_history',
        'share_links', 'user_sessions', 'login_attempts', 'rate_hits', 'password_resets',
        'notification_config', 'contact_messages', 'forms', 'kobo_accounts', 'users', 'settings',
    ];

    protected function setUp(): void { $this->resetDb(); }
    protected function tearDown(): void { $this->resetDb(); }

    private function resetDb(): void
    {
        $pdo = DB::conn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::WORK_TABLES as $t) {
            $pdo->exec("TRUNCATE TABLE $t");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Instancia mínima con datos en TODAS las categorías del diseño. */
    private function seedFixture(): array
    {
        DB::run(
            'INSERT INTO kobo_accounts (label, server_url, email, api_token) VALUES (?, ?, ?, ?)',
            ['acc', 'https://eu.kobotoolbox.org', 'a@test.local', TokenVault::encrypt('tok')]
        );
        $accId = (int) DB::conn()->lastInsertId();
        DB::run(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
            ['Admin', 'admin@bk.test', password_hash('x', PASSWORD_DEFAULT), 'admin']
        );
        $adminId = (int) DB::conn()->lastInsertId();
        // Un formulario con plan de muestra (+ histórico) y un favorito: datos duraderos
        // que el backup COMPLETO debe llevar (tablas añadidas a SEEDED_TABLES).
        DB::run(
            'INSERT INTO forms (kobo_account_id, kobo_asset_uid, name, server_url) VALUES (?, ?, ?, ?)',
            [$accId, 'aBK1', 'Form BK', 'https://eu.kobotoolbox.org']
        );
        $formId = (int) DB::conn()->lastInsertId();
        DB::run('INSERT INTO sample_targets (form_id, team_value, sample_value, target) VALUES (?, ?, ?, ?)', [$formId, 't1', 'a1', 10]);
        DB::run('INSERT INTO sample_target_history (form_id, payload_json) VALUES (?, ?)', [$formId, '{"t1":{"a1":10}}']);
        DB::run('INSERT INTO user_form_favorites (user_id, form_id) VALUES (?, ?)', [$adminId, $formId]);
        // Lo que un backup COMPLETO sí incluye (a diferencia de la semilla):
        DB::run('INSERT INTO audit_log (user_id, action) VALUES (?, ?)', [$adminId, 'login']);
        DB::run(
            'INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)',
            ['Interesado', 'real@fuera.org', 'Hola']
        );
        // Lo que queda FUERA de todo backup:
        DB::run(
            'INSERT INTO user_sessions (user_id, token_id, expires_at, ip) VALUES (?, ?, ?, ?)',
            [$adminId, 'tok-viva', '2030-01-01 00:00:00', '10.9.9.9']
        );
        DB::run(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
            [$adminId, str_repeat('d', 64), '2030-01-01 00:00:00']
        );
        DB::run('INSERT INTO login_attempts (ip) VALUES (?)', ['10.9.9.9']);
        Settings::set('default_locale', 'es');
        Settings::recordCronRun('sync_submissions', ['ok' => true]);

        return ['accId' => $accId, 'adminId' => $adminId, 'formId' => $formId];
    }

    private function export(string $scope): string
    {
        $sql = '';
        DbBackup::export($scope, function (string $chunk) use (&$sql) { $sql .= $chunk; });
        return $sql;
    }

    private function rowCount(string $table): int
    {
        return (int) DB::run("SELECT COUNT(*) AS n FROM $table")->fetch()['n'];
    }

    public function testFullExportIncludesTrailButNeverSecretsNorSessions(): void
    {
        $this->seedFixture();
        $sql = $this->export('full');

        $this->assertStringStartsWith('-- kobomanager-backup v1 | scope full |', $sql);
        // A diferencia de la semilla, el backup completo SÍ lleva auditoría y mensajes.
        $this->assertStringContainsString('INSERT INTO `audit_log`', $sql);
        $this->assertStringContainsString('INSERT INTO `contact_messages`', $sql);
        // Y sigue sin llevar sesiones, tokens de recuperación ni rate-limit.
        foreach (['user_sessions', 'password_resets', 'login_attempts', 'rate_hits'] as $t) {
            $this->assertStringNotContainsString("INSERT INTO `$t`", $sql, "$t no debe viajar en el backup");
        }
        $this->assertStringNotContainsString('cron_runs', $sql, 'la telemetría de crons no viaja');
    }

    public function testFullRoundTripRestoresTrailAndKeepsOperationalTables(): void
    {
        $ids = $this->seedFixture();
        $sql = $this->export('full');

        DB::run('UPDATE users SET name = ? WHERE id = ?', ['Vandalizado', $ids['adminId']]);
        DB::run('DELETE FROM audit_log');
        DB::run('DELETE FROM contact_messages');

        $res = DbBackup::import($sql);
        $this->assertSame('full', $res['scope']);
        $this->assertGreaterThan(0, $res['rows']);

        $this->assertSame('Admin', DB::run('SELECT name FROM users WHERE id = ?', [$ids['adminId']])->fetch()['name']);
        $this->assertSame(1, $this->rowCount('audit_log'), 'la auditoría se restaura en el backup completo');
        $this->assertSame(1, $this->rowCount('contact_messages'));
        // Las tablas operativas fuera del alcance no se tocan: la sesión viva del
        // usuario restaurado sobrevive, y el token de recuperación también (queda
        // como estado operativo actual, no como parte del backup).
        $this->assertSame(1, $this->rowCount('user_sessions'));
        $this->assertSame(1, $this->rowCount('password_resets'));
        // La telemetría de crons sobrevive al restore (clave volátil preservada).
        $this->assertArrayHasKey('sync_submissions', Settings::cronRuns());
    }

    public function testFullBackupCoversSamplePlanAndFavorites(): void
    {
        $this->seedFixture();
        $sql = $this->export('full');
        // Regresión: estas tablas se añadieron con el muestreo (1.32) y los favoritos
        // (1.37); antes quedaban fuera de SEEDED_TABLES y el backup las omitía en silencio.
        $this->assertStringContainsString('INSERT INTO `sample_targets`', $sql);
        $this->assertStringContainsString('INSERT INTO `sample_target_history`', $sql);
        $this->assertStringContainsString('INSERT INTO `user_form_favorites`', $sql);

        // Ida y vuelta: se vandaliza y el restore las recupera.
        DB::run('DELETE FROM user_form_favorites');
        DB::run('DELETE FROM sample_targets');
        DB::run('DELETE FROM sample_target_history');
        DbBackup::import($sql);
        $this->assertSame(1, $this->rowCount('sample_targets'), 'el plan de muestra vuelve');
        $this->assertSame(1, $this->rowCount('sample_target_history'));
        $this->assertSame(1, $this->rowCount('user_form_favorites'), 'los favoritos vuelven');
    }

    public function testFullImportPurgesSessionsOfUsersNotInBackup(): void
    {
        $this->seedFixture();
        $sql = $this->export('full');

        DB::run(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
            ['Posterior', 'post@bk.test', password_hash('x', PASSWORD_DEFAULT), 'admin']
        );
        $ghostId = (int) DB::conn()->lastInsertId();
        DB::run(
            'INSERT INTO user_sessions (user_id, token_id, expires_at) VALUES (?, ?, ?)',
            [$ghostId, 'tok-fantasma', '2030-01-01 00:00:00']
        );

        DbBackup::import($sql);
        $this->assertSame(1, $this->rowCount('users'));
        $this->assertSame(1, $this->rowCount('user_sessions'), 'solo sobrevive la sesión del usuario del backup');
        $this->assertNotFalse(DB::run("SELECT id FROM user_sessions WHERE token_id = 'tok-viva'")->fetch());
    }

    public function testSettingsScopeOnlyTouchesSettings(): void
    {
        $ids = $this->seedFixture();
        $sql = $this->export('settings');
        $this->assertStringStartsWith('-- kobomanager-backup v1 | scope settings |', $sql);
        $this->assertStringNotContainsString('INSERT INTO `users`', $sql);

        Settings::set('default_locale', 'en');
        DB::run('UPDATE users SET name = ? WHERE id = ?', ['Renombrado', $ids['adminId']]);

        $res = DbBackup::import($sql);
        $this->assertSame('settings', $res['scope']);
        $this->assertSame('es', Settings::get('default_locale'), 'la configuración vuelve');
        $this->assertSame('Renombrado', DB::run('SELECT name FROM users WHERE id = ?', [$ids['adminId']])->fetch()['name'], 'los usuarios NO se tocan');
    }

    public function testImportRejectsSeedsAndOutOfScopeContentUntouched(): void
    {
        $ids = $this->seedFixture();

        // Una SEMILLA de demo no es un backup (cabecera distinta): se rechaza.
        try {
            DbBackup::import("-- kobomanager-demo-seed v1 | generado x\nINSERT INTO `users` (`id`) VALUES (1);\n");
            $this->fail('debería rechazar una semilla de demo');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cabecera', $e->getMessage());
        }

        // Un backup de alcance settings con INSERTs fuera de alcance: se rechaza
        // ANTES de tocar la BD.
        try {
            DbBackup::import("-- kobomanager-backup v1 | scope settings | generado x\nINSERT INTO `users` (`id`) VALUES (99);\n");
            $this->fail('debería rechazar INSERTs fuera del alcance declarado');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no permitida', $e->getMessage());
        }

        $this->assertSame(1, $this->rowCount('users'));
        $this->assertNotFalse(DB::run('SELECT id FROM users WHERE id = ?', [$ids['adminId']])->fetch());

        // Y el cron de la demo tampoco acepta un BACKUP como semilla.
        file_put_contents(DEMO_SEED_PATH, $this->export('full'));
        try {
            DemoSeed::restore();
            $this->fail('la semilla de demo no debe aceptar un backup');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cabecera', $e->getMessage());
        }
        @unlink(DEMO_SEED_PATH);
    }
}
