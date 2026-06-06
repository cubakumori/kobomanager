<?php
/**
 * CRON: resumen diario por email. Usa submissions_cache, NO Kobo.
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
require __DIR__ . '/../lib/RowScope.php';

// Día a resumir (el de ayer salvo que se pase uno por argumento).
$day   = $argv[1] ?? date('Y-m-d', strtotime('yesterday'));
$start = $day . ' 00:00:00';
$end   = date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00';

// Candidatos (usuario × formulario con resumen activo). El conteo se calcula aparte
// porque cada (usuario, formulario) puede tener un filtro por filas distinto.
$candidates = DB::run(
    'SELECT u.id AS user_id, u.name, u.email, u.role,
            f.id AS form_id, f.name AS form_name, p.row_filter
     FROM notification_config nc
     JOIN users u ON u.id = nc.user_id AND u.active = 1
     JOIN forms f ON f.id = nc.form_id
     LEFT JOIN user_form_permissions p ON p.user_id = u.id AND p.form_id = f.id
     WHERE nc.daily_summary = 1
     ORDER BY u.id, f.name',
    []
)->fetchAll();

// Agrupar por usuario, contando solo los envíos en alcance.
$byUser = [];
foreach ($candidates as $r) {
    $scope = $r['role'] === 'admin'
        ? null
        : RowScope::normalize($r['row_filter'] ? json_decode($r['row_filter'], true) : null);
    [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

    $cnt = (int) DB::run(
        "SELECT COUNT(*) AS c FROM submissions_cache
         WHERE form_id = ? AND submitted_at >= ? AND submitted_at < ? AND $scopeSql",
        array_merge([$r['form_id'], $start, $end], $scopeP)
    )->fetch()['c'];
    if ($cnt <= 0) continue;

    $byUser[$r['user_id']]['name']  = $r['name'];
    $byUser[$r['user_id']]['email'] = $r['email'];
    $byUser[$r['user_id']]['forms'][] = ['name' => $r['form_name'], 'count' => $cnt];
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
