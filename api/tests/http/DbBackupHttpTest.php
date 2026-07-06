<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP de la copia de seguridad: `GET /admin/db/export` descarga el
 * SQL como attachment (streaming) y `POST /admin/db/import` restaura desde una
 * subida multipart. Solo admin; el bloqueo en demo se cubre en DemoModeHttpTest.
 */
final class DbBackupHttpTest extends HttpTestCase
{
    /** GET que captura también las cabeceras de respuesta (descarga). */
    private function download(string $path, string $jar): array
    {
        $url = self::apiBase() . '/api/v1/' . ltrim($path, '/');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_HTTPHEADER     => ['Origin: ' . self::apiBase()],
        ]);
        $raw        = (string) curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return [
            'status'  => $status,
            'headers' => substr($raw, 0, $headerSize),
            'body'    => substr($raw, $headerSize),
        ];
    }

    /** POST multipart con el archivo de backup. */
    private function upload(string $path, string $filePath, string $jar): array
    {
        $url = self::apiBase() . '/api/v1/' . ltrim($path, '/');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => new CURLFile($filePath, 'application/octet-stream', 'backup.sql')],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_HTTPHEADER     => ['Origin: ' . self::apiBase()],
        ]);
        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw];
    }

    public function testExportDownloadsFullBackupAsAttachment(): void
    {
        $adminId = $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar = $this->login('admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'sub-1', ['_id' => 1, 'a' => 'x']);
        DB::run('INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)', ['N', 'e@x.org', 'm']);

        $res = $this->download('admin/db/export?scope=full', $jar);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="kobomanager-backup-', $res['headers']);
        $this->assertStringStartsWith('-- kobomanager-backup v1 | scope full |', $res['body']);
        $this->assertStringContainsString('INSERT INTO `submissions_cache`', $res['body']);
        $this->assertStringContainsString('INSERT INTO `contact_messages`', $res['body']);

        // Auditado (el login también audita: buscar la acción concreta).
        $n = DB::run("SELECT COUNT(*) AS n FROM audit_log WHERE action = 'db_export' AND user_id = ?", [$adminId])->fetch()['n'];
        $this->assertSame(1, (int) $n);
    }

    public function testRoundTripThroughHttpTransport(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar = $this->login('admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'sub-1', ['_id' => 1, 'a' => 'x']);

        $res = $this->download('admin/db/export?scope=full', $jar);
        $tmp = tempnam(sys_get_temp_dir(), 'kmbk');
        file_put_contents($tmp, $res['body']);

        // Vandalismo entre export e import.
        DB::run('DELETE FROM submissions_cache');

        $up = $this->upload('admin/db/import', $tmp, $jar);
        @unlink($tmp);
        $this->assertSame(200, $up['status'], $up['raw']);
        $this->assertSame('full', $up['json']['data']['scope']);
        $this->assertGreaterThan(0, $up['json']['data']['rows']);
        $this->assertSame(1, (int) DB::run('SELECT COUNT(*) AS n FROM submissions_cache')->fetch()['n']);
    }

    public function testViewerCannotExportNorImport(): void
    {
        $this->seedUser('viewer', 'viewer@test.local', 'Secret123!');
        $jar = $this->login('viewer@test.local', 'Secret123!');

        $res = $this->download('admin/db/export?scope=full', $jar);
        $this->assertSame(403, $res['status']);

        $tmp = tempnam(sys_get_temp_dir(), 'kmbk');
        file_put_contents($tmp, "-- kobomanager-backup v1 | scope settings | x\n");
        $up = $this->upload('admin/db/import', $tmp, $jar);
        @unlink($tmp);
        $this->assertSame(403, $up['status']);
        $this->assertSame('AUTH_INSUFFICIENT_PERMISSIONS', $up['json']['error']['code']);
    }

    public function testImportRejectsForeignFileWith422(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar = $this->login('admin@test.local', 'Secret123!');

        $tmp = tempnam(sys_get_temp_dir(), 'kmbk');
        file_put_contents($tmp, "-- otro archivo\nDROP TABLE users;\n");
        $up = $this->upload('admin/db/import', $tmp, $jar);
        @unlink($tmp);
        $this->assertSame(422, $up['status']);
        $this->assertSame('VALIDATION_ERROR', $up['json']['error']['code']);
        $this->assertSame(1, (int) DB::run('SELECT COUNT(*) AS n FROM users')->fetch()['n'], 'la BD queda intacta');
    }
}
