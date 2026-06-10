<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Caso base para tests de INTEGRACIÓN por HTTP.
 *
 * Levanta (una sola vez por ejecución, compartido entre clases) dos servidores
 * `php -S` efímeros:
 *   - la API real (`api/index.php`) con KM_CONFIG → tests/config.http.php (BD aislada);
 *   - un stub de la API de Kobo (`tests/kobo_stub.php`) al que apunta el `server_url`
 *     de la cuenta de prueba, para cubrir la edición sin tocar Kobo real.
 *
 * A diferencia de DbTestCase (transacción + rollback en proceso), aquí el servidor
 * vive en OTRO proceso, así que los datos sembrados deben COMMITearse: cada test hace
 * TRUNCATE de las tablas de trabajo en setUp y siembra lo que necesita.
 */
abstract class HttpTestCase extends TestCase
{
    /**
     * Servidores efímeros por ARCHIVO DE CONFIG: las clases que necesitan otra
     * config (p. ej. DEMO_MODE) sobreescriben configFile() y reciben su propio
     * par de servidores, compartido entre todas las clases con la misma config.
     * @var array<string, array{apiBase:string, koboBase:string, apiProc:resource, koboProc:resource}>
     */
    private static array $servers = [];

    protected ?string $jar = null;

    /** Tablas que cada test deja limpias antes de sembrar (orden irrelevante: FK checks off). */
    private const WORK_TABLES = [
        'audit_log', 'submission_reviews', 'submissions_cache', 'user_form_permissions',
        'share_links', 'user_sessions', 'login_attempts', 'rate_hits', 'password_resets',
        'notification_config', 'contact_messages', 'forms', 'kobo_accounts', 'users', 'settings',
    ];

    /** Config (constantes) con la que arranca el servidor efímero de la clase. */
    protected static function configFile(): string
    {
        return dirname(__DIR__) . '/config.http.php';
    }

    public static function apiBase(): string { return self::ensureServers(static::configFile())['apiBase']; }
    public static function koboBase(): string { return self::ensureServers(static::configFile())['koboBase']; }

    protected function setUp(): void
    {
        self::ensureServers(static::configFile());
        $this->resetDb();
        $this->jar = tempnam(sys_get_temp_dir(), 'kmjar');
    }

    protected function tearDown(): void
    {
        // Estos tests COMMITean (el servidor vive en otro proceso), así que dejamos las
        // tablas limpias para no colisionar con los tests unitarios (que usan emails fijos).
        $this->resetDb();
        if ($this->jar && file_exists($this->jar)) {
            @unlink($this->jar);
        }
    }

    // ---------- Servidores efímeros ----------

    /** @return array{apiBase:string, koboBase:string} */
    private static function ensureServers(string $config): array
    {
        if (isset(self::$servers[$config])) {
            return self::$servers[$config];
        }
        $apiDir = dirname(__DIR__, 2); // .../api

        $env = getenv();
        $env['KM_CONFIG'] = $config;

        $apiPort  = self::freePort();
        $koboPort = self::freePort();
        $entry = [
            'apiBase'  => "http://127.0.0.1:$apiPort",
            'koboBase' => "http://127.0.0.1:$koboPort",
            'apiProc'  => self::spawn("php -S 127.0.0.1:$apiPort index.php", $apiDir, $env),
            'koboProc' => self::spawn("php -S 127.0.0.1:$koboPort tests/kobo_stub.php", $apiDir, $env),
        ];
        self::$servers[$config] = $entry;

        if (count(self::$servers) === 1) {
            register_shutdown_function([self::class, 'stopServers']);
        }

        if (!self::waitForHealth($entry['apiBase'] . '/api/v1/health', 5.0)) {
            self::stopServers();
            throw new RuntimeException('El servidor API de test no respondió a /health');
        }
        return $entry;
    }

    /** @return resource */
    private static function spawn(string $cmd, string $cwd, array $env)
    {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proc = proc_open($cmd, $spec, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException("No se pudo arrancar: $cmd");
        }
        return $proc;
    }

    public static function stopServers(): void
    {
        foreach (self::$servers as $entry) {
            foreach (['apiProc', 'koboProc'] as $p) {
                if (is_resource($entry[$p])) {
                    proc_terminate($entry[$p]);
                    proc_close($entry[$p]);
                }
            }
        }
        self::$servers = [];
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$sock) {
            throw new RuntimeException("No hay puerto libre: $errstr");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitForHealth(string $url, float $timeoutSec): bool
    {
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 200) {
                return true;
            }
            usleep(50000);
        }
        return false;
    }

    // ---------- Cliente HTTP ----------

    /**
     * Petición a la API de test. Devuelve ['status'=>int, 'json'=>array|null, 'raw'=>string].
     * Usa el cookie jar de la instancia (o uno explícito) y manda Origin propio (pasa CSRF).
     */
    protected function request(string $method, string $path, ?array $body = null, ?string $jar = null): array
    {
        $jar ??= $this->jar;
        $base = self::apiBase();
        $url = $base . '/api/v1/' . ltrim($path, '/');
        $headers = ['Origin: ' . $base, 'Accept: application/json'];

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ['status' => $status, 'json' => json_decode($raw, true), 'raw' => $raw];
    }

    /** Inicia sesión y devuelve la ruta de un cookie jar NUEVO con la sesión. */
    protected function login(string $email, string $password): string
    {
        $jar = tempnam(sys_get_temp_dir(), 'kmjar');
        $res = $this->request('POST', 'auth/login', ['email' => $email, 'password' => $password], $jar);
        $this->assertSame(200, $res['status'], 'login falló: ' . $res['raw']);
        return $jar;
    }

    // ---------- Sembrado (commit, sin transacción) ----------

    protected function resetDb(): void
    {
        $pdo = DB::conn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::WORK_TABLES as $t) {
            $pdo->exec("TRUNCATE TABLE $t");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function seedUser(string $role, string $email, string $password, bool $active = true): int
    {
        DB::run(
            'INSERT INTO users (name, email, password_hash, role, active, locale) VALUES (?, ?, ?, ?, ?, ?)',
            ['Test ' . $role, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active ? 1 : 0, 'es']
        );
        return (int) DB::conn()->lastInsertId();
    }

    /** Cuenta Kobo de prueba; por defecto apunta al stub (para la edición). */
    protected function seedAccount(?string $serverUrl = null): int
    {
        $serverUrl ??= self::koboBase();
        DB::run(
            'INSERT INTO kobo_accounts (label, server_url, email, api_token) VALUES (?, ?, ?, ?)',
            ['acc', $serverUrl, 'a@test.local', TokenVault::encrypt('test-token')]
        );
        return (int) DB::conn()->lastInsertId();
    }

    protected function seedForm(int $accId, ?string $assetUid = null, ?string $schemaJson = null): int
    {
        $assetUid ??= 'asset_' . bin2hex(random_bytes(4));
        DB::run(
            'INSERT INTO forms (kobo_account_id, kobo_asset_uid, name, server_url, active, schema_json)
             VALUES (?, ?, ?, ?, 1, ?)',
            [$accId, $assetUid, 'Form', 'https://eu.kobotoolbox.org', $schemaJson]
        );
        return (int) DB::conn()->lastInsertId();
    }

    protected function grant(int $userId, int $formId, bool $view = true, bool $edit = false, bool $validate = false, ?array $rowFilter = null, ?array $fieldFilter = null): void
    {
        DB::run(
            'INSERT INTO user_form_permissions (user_id, form_id, can_view, can_edit, can_validate, row_filter, field_filter)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $formId, $view ? 1 : 0, $edit ? 1 : 0, $validate ? 1 : 0,
             $rowFilter !== null ? json_encode($rowFilter) : null,
             $fieldFilter !== null ? json_encode($fieldFilter) : null]
        );
    }

    /** Inserta un envío en caché. $payload debe traer _id (y opcionalmente _uuid). */
    protected function seedSubmission(int $formId, string $uid, array $payload, ?string $submittedAt = '2024-01-01 10:00:00'): void
    {
        $payload['_uuid'] ??= $uid;
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, search_text, submitted_at, last_synced_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$formId, $uid, json_encode($payload, JSON_UNESCAPED_UNICODE), SubmissionSearch::textFor($payload), $submittedAt]
        );
    }

    protected function setSetting(string $key, $value): void
    {
        DB::run(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$key, json_encode($value)]
        );
    }
}
