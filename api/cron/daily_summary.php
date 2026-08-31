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

require getenv('KM_CONFIG') ?: __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/Settings.php';
require __DIR__ . '/../lib/Mailer.php';
require __DIR__ . '/../lib/RowScope.php';

// Día a resumir (el de ayer salvo que se pase uno por argumento). El «día» es el
// día natural UTC: `submitted_at` está anclado en UTC (ver SubmissionSync) y el
// gráfico «por día» de Estadísticas agrupa DATE(submitted_at) — así los conteos
// del email coinciden con el gráfico, sea cual sea la TZ del servidor.
$day = $argv[1] ?? (new DateTime('now', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || DateTime::createFromFormat('Y-m-d', $day) === false) {
    fwrite(STDERR, "Uso: php api/cron/daily_summary.php [YYYY-MM-DD]\n");
    exit(1);
}
$start = $day . ' 00:00:00';
$end   = (new DateTime($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

// Idempotencia: una doble ejecución del mismo día (cron + lanzamiento manual, o dos
// crons solapados) duplicaría todos los emails. El candado corta el solape simultáneo
// y la marca del último día enviado corta la repetición (solo cuando el día es el
// implícito «ayer»: un día explícito por argumento es un reenvío deliberado).
$gotLock = ((int) DB::run("SELECT GET_LOCK('km.daily_summary', 0) AS l")->fetch()['l']) === 1;
if (!$gotLock) {
    fwrite(STDOUT, "[SKIP] Otro resumen diario está en marcha.\n");
    exit(0);
}
if (!isset($argv[1]) && Settings::get('daily_summary_last_day', '') === $day) {
    fwrite(STDOUT, "[SKIP] El resumen de $day ya se envió.\n");
    exit(0);
}

// Candidatos (usuario × formulario con resumen diario). El conteo se calcula aparte
// porque cada (usuario, formulario) puede tener un filtro por filas distinto.
// Frecuencia EFECTIVA = notification_config.frequency o, si es NULL / no hay fila,
// el valor por defecto global (notifications_default_frequency). Este cron atiende
// solo 'daily'; 'hourly' y 'every_sync' los atiende lib/Notifier desde el cron de
// sync. Solo cuentan los formularios ACTIVOS que el usuario puede ver (viewer:
// can_view; admin: todos).
$def = Settings::notificationsDefaultFrequency();

$viewerRows = DB::run(
    "SELECT u.id AS user_id, u.name, u.email, u.role,
            f.id AS form_id, f.name AS form_name, p.row_filter
     FROM users u
     JOIN user_form_permissions p ON p.user_id = u.id AND p.can_view = 1
     JOIN forms f ON f.id = p.form_id AND f.active = 1
     LEFT JOIN notification_config nc ON nc.user_id = u.id AND nc.form_id = f.id
     WHERE u.active = 1 AND u.role <> 'admin' AND COALESCE(nc.frequency, ?) = 'daily'
     ORDER BY u.id, f.name",
    [$def]
)->fetchAll();

// Los admins ven todos los formularios activos (sin filtro por filas).
$adminRows = DB::run(
    "SELECT u.id AS user_id, u.name, u.email, u.role,
            f.id AS form_id, f.name AS form_name, NULL AS row_filter
     FROM users u
     JOIN forms f ON f.active = 1
     LEFT JOIN notification_config nc ON nc.user_id = u.id AND nc.form_id = f.id
     WHERE u.active = 1 AND u.role = 'admin' AND COALESCE(nc.frequency, ?) = 'daily'
     ORDER BY u.id, f.name",
    [$def]
)->fetchAll();

$candidates = array_merge($viewerRows, $adminRows);

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
    if (!isset($argv[1])) Settings::set('daily_summary_last_day', $day);
    Settings::recordCronRun('daily_summary', ['ok' => true, 'day' => $day, 'sent' => 0, 'recipients' => 0]);
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

// La marca del día avanza aunque algún envío individual fallara (mejor perder un
// email puntual que repetir todos los demás en un re-run del mismo día).
if (!isset($argv[1])) Settings::set('daily_summary_last_day', $day);
Settings::recordCronRun('daily_summary', [
    'ok'         => $sent === count($byUser),
    'day'        => $day,
    'sent'       => $sent,
    'recipients' => count($byUser),
]);

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
