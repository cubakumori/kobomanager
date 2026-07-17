<?php
/**
 * CLI: genera un par de claves VAPID para Web Push y muestra las constantes a pegar
 * en api/config.php. Ejecutar UNA vez por instancia:
 *
 *   php api/cli/vapid_keys.php
 *
 * No toca ningún archivo ni la BD. OJO: si se cambian las claves más adelante, las
 * suscripciones existentes dejan de recibir push (el navegador las ató a la clave
 * pública vieja) y cada dispositivo tendrá que re-suscribirse desde su perfil.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

require __DIR__ . '/../lib/WebPush.php';

$keys = WebPush::generateKeys();

fwrite(STDOUT, "Claves VAPID generadas. Pega esto en api/config.php:\n\n");
fwrite(STDOUT, "define('VAPID_PUBLIC_KEY', '{$keys['public']}');\n");
fwrite(STDOUT, "define('VAPID_PRIVATE_KEY', '{$keys['private']}');   // SECRETO: no compartir ni versionar\n");
fwrite(STDOUT, "define('VAPID_SUBJECT', 'mailto:tu-email@ejemplo.org');   // contacto del operador\n\n");
fwrite(STDOUT, "Después, cada usuario activa el push en su perfil (por dispositivo).\n");
