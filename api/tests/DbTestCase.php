<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Caso base para tests que tocan la BD: envuelve cada test en una transacción
 * y hace rollback al terminar, de modo que no quede nada escrito en la BD de test.
 * (Los tests no ejecutan DDL, que provocaría un commit implícito.)
 */
abstract class DbTestCase extends TestCase
{
    protected function setUp(): void
    {
        DB::conn()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $pdo = DB::conn();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Limpiar superglobales que algún test pudo tocar.
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = null;
        $_SERVER['HTTP_USER_AGENT'] = null;
    }

    /** Crea un usuario y devuelve su id. */
    protected function makeUser(string $role = 'viewer', bool $active = true, ?string $email = null): int
    {
        $email ??= 'u' . bin2hex(random_bytes(4)) . '@test.local';
        DB::run(
            'INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, ?)',
            ['Test', $email, password_hash('x', PASSWORD_DEFAULT), $role, $active ? 1 : 0]
        );
        return (int) DB::conn()->lastInsertId();
    }

    /** Crea una cuenta Kobo y un formulario; devuelve el id del formulario. */
    protected function makeForm(): int
    {
        DB::run(
            'INSERT INTO kobo_accounts (label, server_url, email, api_token) VALUES (?, ?, ?, ?)',
            ['acc', 'https://eu.kobotoolbox.org', 'a@test.local', 'x']
        );
        $accId = (int) DB::conn()->lastInsertId();
        DB::run(
            'INSERT INTO forms (kobo_account_id, kobo_asset_uid, name, server_url) VALUES (?, ?, ?, ?)',
            [$accId, 'asset_' . bin2hex(random_bytes(4)), 'Form', 'https://eu.kobotoolbox.org']
        );
        return (int) DB::conn()->lastInsertId();
    }
}
