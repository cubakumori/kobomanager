<?php
/**
 * Configuración de TEST con el MODO DEMO activo: la misma config base
 * (config.http.php, BD aislada) + las constantes de demo. La usa el servidor
 * `php -S` efímero propio de DemoModeHttpTest (vía KM_CONFIG).
 */

declare(strict_types=1);

require __DIR__ . '/config.http.php';

define('DEMO_MODE', true);
define('DEMO_RESET_MINUTES', 45);
define('DEMO_LOGIN_HINT', 'admin@demo.org / demo1234');
