<?php
/**
 * CRON: resumen diario por email (sección 9 del plan). Usa submissions_cache, NO Kobo.
 *
 *   php api/cron/daily_summary.php [YYYY-MM-DD]
 *   crontab:  0 7 * * *  php /ruta/api/cron/daily_summary.php
 *
 * Para cada usuario con daily_summary=1 (en notification_config), cuenta los envíos
 * de cada formulario recibidos el día indicado (por defecto, ayer) y, si hay alguno,
 * le envía un email de resumen con Resend.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/Mailer.php';

// Día a resumir (el de ayer salvo que se pase uno por argumento).
$day   = $argv[1] ?? date('Y-m-d', strtotime('yesterday'));
$start = $day . ' 00:00:00';
$end   = date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00';

$rows = DB::run(
    'SELECT u.id AS user_id, u.name, u.email, f.name AS form_name, COUNT(sc.id) AS cnt
     FROM notification_config nc
     JOIN users u ON u.id = nc.user_id AND u.active = 1
     JOIN forms f ON f.id = nc.form_id
     JOIN submissions_cache sc ON sc.form_id = f.id
          AND sc.submitted_at >= ? AND sc.submitted_at < ?
     WHERE nc.daily_summary = 1
     GROUP BY u.id, f.id
     HAVING cnt > 0
     ORDER BY u.id, f.name',
    [$start, $end]
)->fetchAll();

// Agrupar por usuario.
$byUser = [];
foreach ($rows as $r) {
    $byUser[$r['user_id']]['name']  = $r['name'];
    $byUser[$r['user_id']]['email'] = $r['email'];
    $byUser[$r['user_id']]['forms'][] = ['name' => $r['form_name'], 'count' => (int) $r['cnt']];
}

if (!$byUser) {
    fwrite(STDOUT, "Sin resúmenes que enviar para el día $day.\n");
    exit(0);
}

$sent = 0;
foreach ($byUser as $u) {
    [$subject, $html, $text] = build_email($u['name'], $day, $u['forms']);
    $ok = Mailer::send($u['email'], $subject, $html, $text);
    fwrite(STDOUT, sprintf(
        "%s resumen a %s (%d formulario/s con envíos)\n",
        $ok ? '[ENVIADO]' : '[NO ENVIADO]',
        $u['email'],
        count($u['forms'])
    ));
    if ($ok) $sent++;
}

fwrite(STDOUT, "Hecho: $sent email(s) enviado(s) para el día $day.\n");

/** Construye [asunto, html, texto] del email de resumen. */
function build_email(string $name, string $day, array $forms): array {
    $subject = "[KoboManager] Resumen diario — $day";

    $linesText = '';
    $linesHtml = '';
    foreach ($forms as $f) {
        $linesText .= sprintf("  • Formulario \"%s\": %d nuevos envíos\n", $f['name'], $f['count']);
        $linesHtml .= sprintf(
            '<li>Formulario <strong>%s</strong>: %d nuevos envíos</li>',
            htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'),
            $f['count']
        );
    }

    $url = rtrim(APP_URL, '/') . '/forms';

    $text = "Hola $name,\n\nNuevos envíos recibidos el $day:\n\n$linesText\n"
          . "Accede a la app para revisarlos: $url\n\n"
          . "---\nPara desactivar estos avisos, ve a tu perfil en la app.\n";

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $html = "<p>Hola $safeName,</p>"
          . "<p>Nuevos envíos recibidos el <strong>$day</strong>:</p>"
          . "<ul>$linesHtml</ul>"
          . "<p><a href=\"$url\">Accede a la app para revisarlos</a></p>"
          . "<hr><p style=\"color:#888;font-size:12px\">Para desactivar estos avisos, ve a tu perfil en la app.</p>";

    return [$subject, $html, $text];
}
