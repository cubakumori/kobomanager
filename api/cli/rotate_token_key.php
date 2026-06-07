<?php
/**
 * CLI: rotación de CONFIG_TOKEN_KEY.
 *
 * Re-cifra TODOS los `kobo_accounts.api_token` de la clave VIEJA (CONFIG_TOKEN_KEY)
 * a la NUEVA (CONFIG_TOKEN_KEY_NEW), en una sola transacción. Tras rotar, promueve
 * la clave nueva a CONFIG_TOKEN_KEY en config.php y deja CONFIG_TOKEN_KEY_NEW = ''.
 *
 * Uso:
 *   1. Genera la clave nueva:
 *        php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
 *   2. En config.php deja CONFIG_TOKEN_KEY = clave VIEJA y pon CONFIG_TOKEN_KEY_NEW = clave NUEVA.
 *   3. Ensayo:   php api/cli/rotate_token_key.php --dry-run
 *   4. Rotación: php api/cli/rotate_token_key.php
 *   5. Edita config.php: CONFIG_TOKEN_KEY = clave NUEVA, CONFIG_TOKEN_KEY_NEW = ''.
 *
 * Procedimiento completo y rollback en DEPLOY.md §12.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/TokenVault.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$old = CONFIG_TOKEN_KEY;
$new = CONFIG_TOKEN_KEY_NEW;

if ($new === '' || $new === $old) {
    fwrite(STDERR, "ERROR: define CONFIG_TOKEN_KEY_NEW en config.php con la clave NUEVA (distinta de CONFIG_TOKEN_KEY).\n");
    exit(1);
}
// Validación temprana de longitud de ambas claves (lanza si no son válidas).
try {
    TokenVault::encrypt('x', $old);
    TokenVault::encrypt('x', $new);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

$rows = DB::run('SELECT id, label, api_token FROM kobo_accounts ORDER BY id')->fetchAll();
printf("Cuentas a rotar: %d%s\n", count($rows), $dryRun ? '  (DRY-RUN, no se escribe)' : '');

$pdo = DB::conn();
if (!$dryRun) {
    $pdo->beginTransaction();
}

$done = 0;
try {
    foreach ($rows as $r) {
        // Descifra con la vieja (verifica que la cuenta usa de verdad la clave vieja).
        $plain = TokenVault::decrypt($r['api_token'], $old);
        // Re-cifra con la nueva.
        $reenc = TokenVault::encrypt($plain, $new);
        // Verificación de ida y vuelta antes de escribir.
        if (TokenVault::decrypt($reenc, $new) !== $plain) {
            throw new RuntimeException("Verificación fallida en cuenta id={$r['id']}");
        }
        if (!$dryRun) {
            DB::run('UPDATE kobo_accounts SET api_token = ? WHERE id = ?', [$reenc, $r['id']]);
        }
        printf("  [%s] id=%d %s\n", $dryRun ? 'ok' : 'ROTADA', $r['id'], $r['label']);
        $done++;
    }
} catch (Throwable $e) {
    if (!$dryRun) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR (sin cambios): ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$dryRun) {
    $pdo->commit();
}

printf("\n%s %d cuenta(s).\n", $dryRun ? 'Verificadas' : 'Rotadas', $done);
if (!$dryRun) {
    echo "AHORA: en config.php pon CONFIG_TOKEN_KEY = la clave nueva y CONFIG_TOKEN_KEY_NEW = ''.\n";
}
