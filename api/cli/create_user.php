<?php
/**
 * CLI: crear (o actualizar la contraseña de) un usuario de la app.
 *
 * Uso:
 *   php api/cli/create_user.php <email> <password> <name> [admin|viewer]
 *
 * Pensado para crear el primer administrador, ya que la creación vía API
 * requiere estar autenticado como admin.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';

$email    = $argv[1] ?? null;
$password = $argv[2] ?? null;
$name     = $argv[3] ?? null;
$role     = $argv[4] ?? 'admin';

if (!$email || !$password || !$name) {
    fwrite(STDERR, "Uso: php api/cli/create_user.php <email> <password> <name> [admin|viewer]\n");
    exit(1);
}
if (!in_array($role, ['admin', 'viewer'], true)) {
    fwrite(STDERR, "Rol no válido: $role (usa 'admin' o 'viewer')\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$existing = DB::run('SELECT id FROM users WHERE email = ?', [$email])->fetch();
if ($existing) {
    DB::run(
        'UPDATE users SET name = ?, password_hash = ?, role = ?, active = 1 WHERE id = ?',
        [$name, $hash, $role, $existing['id']]
    );
    echo "Usuario actualizado (id={$existing['id']}): $email [$role]\n";
} else {
    DB::run(
        'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
        [$name, $email, $hash, $role]
    );
    echo "Usuario creado (id=" . DB::conn()->lastInsertId() . "): $email [$role]\n";
}
