<?php
/**
 * CLI: instalador de KoboManager.
 *
 * Con `api/config.php` ya relleno, un solo comando deja la instancia lista:
 *   1. Verifica requisitos (PHP 8.1+, extensiones, claves no-placeholder, BD).
 *   2. Aplica el esquema (db/*.sql, en orden) si la base de datos está vacía.
 *   3. Crea el primer administrador (interactivo, o con --admin).
 *   4. Sugiere borrar db/ del servidor (--clean lo hace; se niega en un
 *      checkout de desarrollo).
 *
 * Uso:
 *   php api/cli/install.php
 *   php api/cli/install.php --admin admin@dominio.org 'Contraseña' 'Nombre'
 *   php api/cli/install.php --clean
 *
 * Re-ejecutarlo es seguro: con el esquema ya instalado no toca nada, y solo
 * ofrece crear el admin si no existe ningún usuario.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

$apiDir  = dirname(__DIR__);
$rootDir = dirname($apiDir);
$dbDir   = $rootDir . '/db';

$args  = array_slice($argv, 1);
$clean = in_array('--clean', $args, true);
$adminArgs = null;
if (($i = array_search('--admin', $args, true)) !== false) {
    $adminArgs = array_slice($args, $i + 1, 3);
    if (count($adminArgs) !== 3) {
        fwrite(STDERR, "  ✗ --admin requiere 3 argumentos: <email> <password> <nombre>\n");
        exit(1);
    }
}

function ok(string $msg): void   { echo "  ✓ $msg\n"; }
function fail(string $msg): never { fwrite(STDERR, "  ✗ $msg\n"); exit(1); }

echo "KoboManager — instalador\n\n";

// ---------- 1. Requisitos ----------
echo "Requisitos:\n";

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fail('Se necesita PHP 8.1 o superior (tienes ' . PHP_VERSION . ').');
}
ok('PHP ' . PHP_VERSION);

foreach (['sodium', 'pdo_mysql', 'curl'] as $ext) {
    if (!extension_loaded($ext)) {
        fail("Falta la extensión PHP «{$ext}».");
    }
}
ok('Extensiones sodium, pdo_mysql y curl');

// Config: por defecto api/config.php; KM_CONFIG permite otra (tests, entornos).
$configFile = getenv('KM_CONFIG') ?: $apiDir . '/config.php';
if (!is_file($configFile)) {
    fail("No existe $configFile — copia api/config.example.php a api/config.php y rellénalo.");
}
require $configFile;
ok('config.php cargado (' . $configFile . ')');

// Claves: presentes, con pinta de reales (64 hex) y no de placeholder.
foreach (['CONFIG_TOKEN_KEY', 'JWT_SECRET'] as $const) {
    if (!defined($const) || !preg_match('/^[0-9a-f]{64}$/i', (string) constant($const))) {
        fail("$const no está definido o no son 64 caracteres hex — genera uno (ver config.example.php).");
    }
}
ok('CONFIG_TOKEN_KEY y JWT_SECRET con formato válido');

require $apiDir . '/lib/DB.php';
try {
    $pdo = DB::conn();
} catch (Throwable $e) {
    fail('No se pudo conectar a la base de datos: ' . $e->getMessage());
}
$serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
ok('Conexión a la BD «' . DB_NAME . '» (servidor ' . $serverVersion . ')');

// ---------- 2. Esquema ----------
echo "\nEsquema:\n";

// scandir y no glob(): la ruta puede contener metacaracteres de glob ([], ?, *).
// No se exige todavía que existan: los db/*.sql solo hacen falta si la BD está
// vacía. Con el esquema ya aplicado (a mano por SSH/phpMyAdmin), la carpeta db/
// es prescindible y el instalador sigue para crear el primer admin.
$files = array_values(array_filter(
    is_dir($dbDir) ? scandir($dbDir) : [],
    static fn($f) => str_ends_with($f, '.sql')
));
sort($files);
$files = array_map(static fn($f) => $dbDir . '/' . $f, $files);

// Tablas que debe tener una instalación completa (las crea db/001_schema.sql).
$expected = [
    'kobo_accounts', 'users', 'user_sessions', 'forms', 'submissions_cache',
    'submission_reviews', 'user_form_permissions', 'notification_config', 'audit_log',
    'login_attempts', 'rate_hits', 'settings', 'password_resets', 'share_links',
    'contact_messages',
];
$placeholders = implode(',', array_fill(0, count($expected), '?'));
$present = DB::run(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name IN ($placeholders)",
    $expected
)->fetchAll(PDO::FETCH_COLUMN);

if (count($present) === count($expected)) {
    ok('El esquema ya está instalado (' . count($expected) . ' tablas) — no se toca.');
} elseif (count($present) > 0) {
    fail('La base de datos tiene un esquema PARCIAL (' . count($present) . ' de ' . count($expected)
        . ' tablas). Recrea una base de datos vacía y vuelve a ejecutar el instalador.');
} else {
    // BD vacía: ahora sí hacen falta los archivos de esquema.
    if (!$files) {
        fail("La base de datos está vacía y no se encontraron archivos de esquema en $dbDir.\n"
            . "     Sube la carpeta db/ junto a api/ (o aplica db/*.sql a mano) y reintenta.");
    }
    foreach ($files as $file) {
        foreach (split_sql_statements((string) file_get_contents($file)) as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (Throwable $e) {
                fail(basename($file) . ': ' . $e->getMessage());
            }
        }
        ok('Aplicado ' . basename($file));
    }
}

// ---------- 3. Primer administrador ----------
echo "\nPrimer administrador:\n";

$userCount = (int) DB::run('SELECT COUNT(*) AS n FROM users')->fetch()['n'];
if ($userCount > 0) {
    ok("Ya hay $userCount usuario(s) — no se crea ninguno.");
} else {
    if ($adminArgs !== null) {
        [$email, $password, $name] = $adminArgs;
    } else {
        $email    = trim((string) readline('  Email: '));
        $password = trim((string) readline('  Contraseña (mín. 8): '));
        $name     = trim((string) readline('  Nombre: '));
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail("Email no válido: $email (necesita dominio con punto, p. ej. admin@tudominio.org).");
    }
    if (strlen($password) < 8) {
        fail('La contraseña debe tener al menos 8 caracteres.');
    }
    if ($name === '') {
        fail('El nombre no puede estar vacío.');
    }
    DB::run(
        'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
        [$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']
    );
    ok("Administrador creado: $email");
}

// ---------- 4. Limpieza opcional de db/ ----------
echo "\n";
if ($clean) {
    if (is_dir($rootDir . '/.git')) {
        fail('--clean se niega a borrar db/ en un checkout de desarrollo (existe .git). '
            . 'Solo tiene sentido en el servidor.');
    }
    foreach ($files as $f) { @unlink($f); }
    @rmdir($dbDir);
    ok('Carpeta db/ eliminada (la app no la usa en ejecución).');
} else {
    echo "Nota: la carpeta db/ ya no hace falta en el servidor (la app no la lee en\n"
        . "ejecución); puedes borrarla a mano o re-ejecutar con --clean.\n";
}

echo "\nInstalación completa. Comprueba /api/v1/health y entra con tu administrador.\n";

/**
 * Divide un .sql en sentencias ejecutables. Respeta `;` dentro de cadenas e
 * identificadores Y dentro de comentarios (`-- …` hasta fin de línea y
 * bloques estilo C), que se copian verbatim (MySQL los acepta dentro de la
 * sentencia). Suficiente para el DDL canónico de db/ (sin DELIMITER).
 */
function split_sql_statements(string $sql): array
{
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null; // comilla abierta: ' " `
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($quote !== null) {
            $buf .= $ch;
            if ($ch === '\\' && $quote !== '`') { $buf .= $sql[++$i] ?? ''; continue; }
            if ($ch === $quote) { $quote = null; }
            continue;
        }
        // Comentario `--` hasta fin de línea: copiar sin interpretar `;`.
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $len && $sql[$i] !== "\n") { $buf .= $sql[$i]; $i++; }
            $buf .= "\n";
            continue;
        }
        // Comentario de bloque: copiar sin interpretar `;`.
        if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $end = $end === false ? $len : $end + 2;
            $buf .= substr($sql, $i, $end - $i);
            $i = $end - 1;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buf .= $ch; continue; }
        if ($ch === ';') {
            $stmt = trim($buf);
            // Descartar restos que sean SOLO comentarios/espacio.
            if ($stmt !== '' && (string) preg_replace('/^\s*--.*$/m', '', $stmt) !== '' && trim((string) preg_replace('/^\s*--.*$/m', '', $stmt)) !== '') {
                $stmts[] = $stmt;
            }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $stmt = trim($buf);
    if ($stmt !== '' && trim((string) preg_replace('/^\s*--.*$/m', '', $stmt)) !== '') {
        $stmts[] = $stmt;
    }
    return $stmts;
}
