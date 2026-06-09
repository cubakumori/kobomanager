<?php
/**
 * Bootstrap de los tests (PHPUnit).
 * Define la configuración apuntando a una BD de test SEPARADA (kobomanager_test)
 * y carga el autoloader de Composer (classmap de lib/).
 *
 * Requisitos previos (una vez):
 *   mysql -e "CREATE DATABASE kobomanager_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
 *   for f in db/*.sql; do mysql kobomanager_test < "$f"; done
 *   (y conceder privilegios al usuario de BD sobre kobomanager_test)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// --- Configuración de TEST (no es config.php; BD aislada) ---
// Mismo bloque de constantes que usa el servidor HTTP efímero (vía KM_CONFIG), para que
// los tests en proceso y los de integración compartan EXACTAMENTE la misma config.
require __DIR__ . '/config.http.php';
