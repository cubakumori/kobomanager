<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DemoSeed — export/restore de la semilla de la demo (ida y vuelta real contra
 * la BD de test). No extiende DbTestCase porque restore() gestiona su PROPIA
 * transacción (beginTransaction anidado fallaría): limpia con TRUNCATE + commit,
 * como los tests HTTP.
 */
final class DemoSeedTest extends TestCase
{
    private const WORK_TABLES = [
        'audit_log', 'submission_reviews', 'submissions_cache', 'user_form_permissions',
        'share_links', 'user_sessions', 'login_attempts', 'rate_hits', 'password_resets',
        'notification_config', 'contact_messages', 'forms', 'kobo_accounts', 'users', 'settings',
    ];

    protected function setUp(): void
    {
        $this->resetDb();
        @unlink(DEMO_SEED_PATH);
    }

    protected function tearDown(): void
    {
        $this->resetDb();
        @unlink(DEMO_SEED_PATH);
    }

    private function resetDb(): void
    {
        $pdo = DB::conn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::WORK_TABLES as $t) {
            $pdo->exec("TRUNCATE TABLE $t");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Demo mínima pero con contenido «difícil» (comillas, `;`, emoji, salto de línea). */
    private function seedFixture(): array
    {
        DB::run(
            'INSERT INTO kobo_accounts (label, server_url, email, api_token) VALUES (?, ?, ?, ?)',
            ['demo', 'https://eu.kobotoolbox.org', 'demo@test.local', TokenVault::encrypt('token-demo')]
        );
        $accId = (int) DB::conn()->lastInsertId();

        DB::run(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
            ["Admin; O'Demo", 'admin@demo.test', password_hash('demo1234', PASSWORD_DEFAULT), 'admin']
        );
        $adminId = (int) DB::conn()->lastInsertId();
        DB::run(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
            ['Viewer', 'viewer@demo.test', password_hash('demo1234', PASSWORD_DEFAULT), 'viewer']
        );
        $viewerId = (int) DB::conn()->lastInsertId();

        DB::run(
            'INSERT INTO forms (kobo_account_id, kobo_asset_uid, name, server_url, active) VALUES (?, ?, ?, ?, 1)',
            [$accId, 'asset_seed', "Encuesta \"difícil\"; 🌱", 'https://eu.kobotoolbox.org']
        );
        $formId = (int) DB::conn()->lastInsertId();

        $payload = ['_id' => 1, '_uuid' => 'sub-1', 'nombre' => "Ana; la de 'siempre'", 'nota' => "línea1\nlínea2 🌍", 'ruta' => 'C:\\datos\\x'];
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, search_text, submitted_at, last_synced_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$formId, 'sub-1', json_encode($payload, JSON_UNESCAPED_UNICODE), 'ana', '2026-01-15 10:00:00']
        );
        ValidationStatus::recordReview('sub-1', $adminId, 'app', 'approved', 'ok; revisado -- sin dudas');
        DB::run(
            'INSERT INTO user_form_permissions (user_id, form_id, can_view, row_filter) VALUES (?, ?, 1, ?)',
            [$viewerId, $formId, json_encode(['match' => 'all', 'groups' => [['match' => 'any', 'conditions' => [['field' => 'nombre', 'op' => 'in', 'values' => ['Ana']]]]]])]
        );
        DB::run(
            'INSERT INTO share_links (token, form_id, created_by, label) VALUES (?, ?, ?, ?)',
            [str_repeat('a', 64), $formId, $adminId, 'Enlace demo']
        );
        DB::run("INSERT INTO notification_config (user_id, form_id, frequency) VALUES (?, ?, 'daily')", [$adminId, $formId]);
        Settings::set('default_locale', 'es');

        return ['accId' => $accId, 'adminId' => $adminId, 'viewerId' => $viewerId, 'formId' => $formId];
    }

    private function rowCount(string $table): int
    {
        return (int) DB::run("SELECT COUNT(*) AS n FROM $table")->fetch()['n'];
    }

    public function testExportExcludesPrivateTablesAndVolatileSettings(): void
    {
        $ids = $this->seedFixture();
        // Rastro «privado» que jamás debe viajar en la semilla.
        DB::run('INSERT INTO audit_log (user_id, action) VALUES (?, ?)', [$ids['adminId'], 'login']);
        DB::run('INSERT INTO login_attempts (ip) VALUES (?)', ['10.0.0.1']);
        DB::run('INSERT INTO rate_hits (bucket, ip) VALUES (?, ?)', ['share', '10.0.0.1']);
        DB::run(
            'INSERT INTO user_sessions (user_id, token_id, expires_at, ip) VALUES (?, ?, ?, ?)',
            [$ids['adminId'], 'tok-priv', '2030-01-01 00:00:00', '10.0.0.1']
        );
        DB::run(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
            [$ids['adminId'], str_repeat('b', 64), '2030-01-01 00:00:00']
        );
        DB::run(
            'INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)',
            ['Interesado', 'real@fuera.org', 'Me interesa el proyecto']
        );
        Settings::recordCronRun('sync_submissions', ['ok' => true]);

        $res = DemoSeed::export();
        $this->assertGreaterThan(0, $res['rows']);
        $this->assertFileExists(DEMO_SEED_PATH);

        $sql = (string) file_get_contents(DEMO_SEED_PATH);
        $this->assertStringStartsWith('-- kobomanager-demo-seed v1', $sql);
        foreach (['audit_log', 'login_attempts', 'rate_hits', 'user_sessions', 'password_resets', 'contact_messages'] as $private) {
            $this->assertStringNotContainsString("INSERT INTO `$private`", $sql, "$private no debe viajar en la semilla");
        }
        $this->assertStringContainsString('INSERT INTO `settings`', $sql);
        $this->assertStringNotContainsString('cron_runs', $sql, 'la telemetría de crons no viaja en la semilla');
        $this->assertStringNotContainsString('10.0.0.1', $sql, 'ninguna IP debe viajar en la semilla');
    }

    public function testRoundTripRestoresSeededStateExactly(): void
    {
        $ids = $this->seedFixture();
        $originalPayload = DB::run('SELECT json_payload FROM submissions_cache WHERE submission_uid = ?', ['sub-1'])->fetch()['json_payload'];
        DemoSeed::export();

        // «Visitantes» ensucian la demo: cambian, borran y crean de todo.
        DB::run('UPDATE users SET name = ? WHERE id = ?', ['Vandalizado', $ids['adminId']]);
        DB::run('DELETE FROM submissions_cache WHERE submission_uid = ?', ['sub-1']);
        DB::run('DELETE FROM submission_reviews');
        DB::run(
            'INSERT INTO share_links (token, form_id, created_by, label) VALUES (?, ?, ?, ?)',
            [str_repeat('c', 64), $ids['formId'], $ids['adminId'], 'Creado por visitante']
        );
        DB::run('INSERT INTO audit_log (user_id, action) VALUES (?, ?)', [$ids['adminId'], 'review']);
        Settings::set('default_locale', 'en');
        // Lo que debe SOBREVIVIR al reset:
        DB::run(
            'INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)',
            ['Interesado', 'real@fuera.org', 'Quiero usarlo en mi ONG']
        );
        DB::run(
            'INSERT INTO user_sessions (user_id, token_id, expires_at) VALUES (?, ?, ?)',
            [$ids['viewerId'], 'tok-visitante', '2030-01-01 00:00:00']
        );
        Settings::recordCronRun('sync_submissions', ['ok' => true]);

        $res = DemoSeed::restore();
        $this->assertGreaterThan(0, $res['rows']);

        // Estado de semilla restaurado (con fidelidad byte a byte en el JSON).
        $admin = DB::run('SELECT name FROM users WHERE id = ?', [$ids['adminId']])->fetch();
        $this->assertSame("Admin; O'Demo", $admin['name']);
        $sub = DB::run('SELECT json_payload FROM submissions_cache WHERE submission_uid = ?', ['sub-1'])->fetch();
        $this->assertNotFalse($sub, 'el envío borrado por el visitante debe volver');
        $this->assertJsonStringEqualsJsonString($originalPayload, $sub['json_payload']);
        $this->assertSame(1, $this->rowCount('submission_reviews'));
        $this->assertSame(1, $this->rowCount('share_links'), 'el enlace creado por el visitante desaparece');
        $this->assertSame('es', Settings::get('default_locale'));
        // El token de la cuenta Kobo sobrevive cifrado y descifrable.
        $tok = DB::run('SELECT api_token FROM kobo_accounts WHERE id = ?', [$ids['accId']])->fetch();
        $this->assertSame('token-demo', TokenVault::decrypt($tok['api_token']));

        // Efímeras vaciadas; intocables preservadas.
        $this->assertSame(0, $this->rowCount('audit_log'));
        $this->assertSame(1, $this->rowCount('contact_messages'), 'los mensajes reales sobreviven al reset');
        $this->assertSame(1, $this->rowCount('user_sessions'), 'las sesiones de visitantes sobreviven al reset');
        // La telemetría de crons sobrevive (no viaja en la semilla y el DELETE la respeta).
        $this->assertArrayHasKey('sync_submissions', Settings::cronRuns());
    }

    public function testRestorePurgesSessionsOfUsersNotInSeed(): void
    {
        $this->seedFixture();
        DemoSeed::export();

        // Usuario creado DESPUÉS de la semilla (solo posible con la demo apagada)
        // con sesión viva: al restaurar, usuario y sesión deben desaparecer.
        DB::run(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
            ['Fugaz', 'fugaz@demo.test', password_hash('x', PASSWORD_DEFAULT), 'viewer']
        );
        $ghostId = (int) DB::conn()->lastInsertId();
        DB::run(
            'INSERT INTO user_sessions (user_id, token_id, expires_at) VALUES (?, ?, ?)',
            [$ghostId, 'tok-fugaz', '2030-01-01 00:00:00']
        );

        DemoSeed::restore();
        $this->assertSame(2, $this->rowCount('users'));
        $this->assertSame(0, $this->rowCount('user_sessions'), 'la sesión huérfana se purga');
    }

    public function testRestoreRejectsForeignFileAndLeavesDbUntouched(): void
    {
        $ids = $this->seedFixture();

        file_put_contents(DEMO_SEED_PATH, "-- otro archivo cualquiera\nDROP TABLE users;\n");
        try {
            DemoSeed::restore();
            $this->fail('debería rechazar un archivo sin cabecera');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cabecera', $e->getMessage());
        }

        // Cabecera correcta pero sentencia prohibida: se valida ANTES de tocar la BD.
        file_put_contents(DEMO_SEED_PATH, "-- kobomanager-demo-seed v1\nDELETE FROM users;\n");
        try {
            DemoSeed::restore();
            $this->fail('debería rechazar sentencias que no sean INSERT/SET');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no permitida', $e->getMessage());
        }

        file_put_contents(DEMO_SEED_PATH, "-- kobomanager-demo-seed v1\nINSERT INTO otra_tabla (a) VALUES (1);\n");
        try {
            DemoSeed::restore();
            $this->fail('debería rechazar INSERT en tablas fuera de la semilla');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no permitida', $e->getMessage());
        }

        $this->assertSame(2, $this->rowCount('users'), 'la BD queda intacta si la semilla no valida');
        $this->assertNotFalse(DB::run('SELECT id FROM users WHERE id = ?', [$ids['adminId']])->fetch());
    }

    public function testRestoreRollsBackOnMidRestoreFailure(): void
    {
        $ids = $this->seedFixture();
        DemoSeed::export();

        // Semilla que valida (INSERT en tabla de semilla) pero revienta a MITAD de la
        // transacción (columna inexistente = esquema divergente). Todo debe revertirse.
        $sql = (string) file_get_contents(DEMO_SEED_PATH);
        $sql .= "INSERT INTO `users` (`columna_que_no_existe`) VALUES (1);\n";
        file_put_contents(DEMO_SEED_PATH, $sql);

        DB::run('UPDATE users SET name = ? WHERE id = ?', ['Estado previo', $ids['adminId']]);
        try {
            DemoSeed::restore();
            $this->fail('debería fallar con la columna inexistente');
        } catch (PDOException) {
            // esperado
        }
        $admin = DB::run('SELECT name FROM users WHERE id = ?', [$ids['adminId']])->fetch();
        $this->assertSame('Estado previo', $admin['name'], 'ROLLBACK: la demo conserva el estado anterior');
    }
}
