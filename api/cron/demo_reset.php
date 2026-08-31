<?php
/**
 * CRON: restaura la DEMO desde la semilla (DEMO_SEED_PATH) cada DEMO_RESET_MINUTES.
 *
 *   php api/cron/demo_reset.php [--force]
 *   crontab (cada minuto; el script se auto-regula):  * * * * *  php /ruta/api/cron/demo_reset.php
 *
 * El ciclo lo gobierna DEMO_RESET_MINUTES (el mismo número que muestra el diálogo
 * de bienvenida): el script guarda la marca del último reset en `settings`
 * (demo_last_reset_at) y solo restaura cuando el ciclo ha vencido. Así el crontab
 * no codifica el intervalo y el diálogo nunca miente. `--force` restaura ya.
 *
 * Guardas: solo actúa con DEMO_MODE ENCENDIDO (nunca machaca una instancia real
 * por un crontab olvidado) y con una semilla válida (cabecera + validación; ver
 * lib/DemoSeed). La restauración es UNA transacción: si falla a mitad, ROLLBACK
 * y la demo conserva el estado anterior. No toca config.php ni el flag.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

require getenv('KM_CONFIG') ?: __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/Settings.php';
require __DIR__ . '/../lib/Demo.php';
require __DIR__ . '/../lib/SqlScript.php';
require __DIR__ . '/../lib/DbSnapshot.php';
require __DIR__ . '/../lib/DemoSeed.php';

$force = in_array('--force', array_slice($argv, 1), true);

if (!Demo::enabled()) {
    // Flag apagado = mantenimiento (o crontab olvidado): no restaurar nada.
    fwrite(STDOUT, "[SKIP] DEMO_MODE está apagado — no se restaura.\n");
    exit(0);
}

if (DemoSeed::path() === '') {
    fwrite(STDERR, "[ERR] DEMO_SEED_PATH no está configurado en config.php.\n");
    Settings::recordCronRun('demo_reset', ['ok' => false, 'error' => 'DEMO_SEED_PATH no configurado']);
    exit(1);
}

// Auto-regulación: el crontab corre cada minuto; el ciclo real lo pone la config.
$minutes = Demo::resetMinutes();
$last    = (int) Settings::get('demo_last_reset_at', 0);
if (!$force && $last > 0 && (time() - $last) < $minutes * 60) {
    exit(0); // ciclo aún no vencido: salida silenciosa (corre cada minuto)
}

// Solo una restauración a la vez: la marca se escribe al FINAL, así que un restore
// que tarde >1 min (o dos procesos simultáneos) dispararía un segundo restore
// concurrente sin este candado.
$gotLock = ((int) DB::run("SELECT GET_LOCK('km.demo_reset', 0) AS l")->fetch()['l']) === 1;
if (!$gotLock) {
    fwrite(STDOUT, "[SKIP] Otra restauración de la demo está en marcha.\n");
    exit(0);
}

try {
    try {
        $res = DemoSeed::restore();
    } catch (PDOException $e) {
        // Deadlock/lock timeout contra la escritura de un visitante: un reintento y,
        // si persiste, se deja para la siguiente pasada del cron (la demo sigue íntegra).
        if (in_array((string) $e->getCode(), ['40001', 'HY000'], true)) {
            sleep(1);
            $res = DemoSeed::restore();
        } else {
            throw $e;
        }
    }
    Settings::set('demo_last_reset_at', time());
    Settings::recordCronRun('demo_reset', ['ok' => true] + $res);
    fwrite(STDOUT, sprintf(
        "Hecho: demo restaurada (%d sentencias, %d filas).\n",
        $res['statements'], $res['rows']
    ));
} catch (Throwable $e) {
    Settings::recordCronRun('demo_reset', ['ok' => false, 'error' => $e->getMessage()]);
    fwrite(STDERR, '[ERR] ' . $e->getMessage() . "\n");
    exit(1);
}
