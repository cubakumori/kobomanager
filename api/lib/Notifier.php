<?php
/**
 * Avisos «casi inmediatos» de envíos nuevos (frecuencias hourly/every_sync), por
 * email y por Web Push.
 *
 * Lo invoca el cron de sincronización (cron/sync_submissions.php) tras cada pasada:
 * para cada usuario cuya frecuencia EFECTIVA (notification_config.frequency o, en su
 * ausencia, el default global) sea 'hourly' o 'every_sync', cuenta los envíos con
 * `submitted_at` posterior a su marca de agua (`last_notified_at`, UTC) y le envía UN
 * email agrupado («N envíos nuevos en {formulario}») más, si suscribió dispositivos
 * (push_subscriptions, opt-in por dispositivo y claves VAPID en config), una
 * notificación push con el mismo recuento (lib/WebPush). El resumen 'daily' sigue en
 * su propio cron (cron/daily_summary.php) y es solo email.
 *
 * Guardarraíles (ver ROADMAP «Notificaciones casi inmediatas»):
 *   - Scoping por filas: el conteo aplica el row_filter del usuario (RowScope), igual
 *     que el resumen diario — no se avisa de envíos fuera de su alcance.
 *   - Anti-inundación: mínimo un intervalo entre emails por usuario ('hourly' = 1 h
 *     desde su último aviso hourly; 'every_sync' = la propia cadencia del cron); lo no
 *     avisado queda acumulado tras la marca de agua y sale agrupado en el siguiente.
 *   - Horario de silencio global opcional (Settings::notificationsQuiet, en hora de
 *     APP_TIMEZONE): dentro del tramo no se envía; la marca de agua no avanza, así que
 *     lo acumulado sale agrupado al terminar el silencio.
 *   - El aviso es solo RECUENTO + enlace a la app: nunca contenido del envío (no se
 *     filtra por email lo que el scoping por filas/columnas protege).
 *
 * Marca de agua NULL = sin línea base: se ancla a «ahora» SIN avisar (primer paso tras
 * el opt-in o tras heredar un default vivo), para no inundar con el histórico. Para
 * los suscritos por default global sin fila propia, se crea la fila con frequency
 * NULL (sigue significando «hereda el default») solo para sostener la marca de agua.
 */
class Notifier {

    /** Frecuencias que gestiona este notificador (con marca de agua). */
    public const LIVE_FREQUENCIES = ['hourly', 'every_sync'];

    /** Intervalo mínimo entre avisos 'hourly' por usuario (segundos). */
    private const HOURLY_INTERVAL = 3600;

    /** Tolerancia al desfase del cron (un cron a :00/:15/... nunca cae exacto). */
    private const INTERVAL_SLACK = 120;

    /** Fallos de push consecutivos (no-410) tras los que se poda la suscripción. */
    private const PUSH_FAIL_LIMIT = 10;

    /**
     * Ejecuta una pasada del notificador. Devuelve un resumen apto para
     * Settings::recordCronRun: { sent, push_sent, recipients, baselined, errors, skipped? }.
     *
     * Canales: email (si hay transporte) y Web Push (si hay claves VAPID y el usuario
     * suscribió dispositivos) — mismo contenido, misma cadencia y guardarraíles. La
     * marca de agua avanza si CUALQUIER canal entregó (el aviso llegó).
     *
     * @param DateTimeImmutable|null $nowUtc «Ahora» inyectable (tests); default = now UTC.
     * @param callable|null $send      fn(to, subject, html, text): bool — default Mailer::send.
     * @param callable|null $pushSend  fn(sub, payloadJson): int (status HTTP) — default WebPush::send.
     */
    public static function run(?DateTimeImmutable $nowUtc = null, ?callable $send = null, ?callable $pushSend = null): array {
        $now    = $nowUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');

        $emailEnabled = $send !== null || Settings::mailConfigured();
        $pushEnabled  = $pushSend !== null || WebPush::configured();
        if (!$emailEnabled && !$pushEnabled) {
            return ['sent' => 0, 'push_sent' => 0, 'recipients' => 0, 'baselined' => 0, 'errors' => 0, 'skipped' => 'no_channel_configured'];
        }
        $send     ??= fn(string $to, string $subject, string $html, string $text): bool
            => Mailer::send($to, $subject, $html, $text);
        $pushSend ??= fn(array $sub, string $payload): int => WebPush::send($sub, $payload);

        $quiet = Settings::notificationsQuiet();
        if ($quiet !== null && self::inQuietHours($now, $quiet['start'], $quiet['end'])) {
            return ['sent' => 0, 'push_sent' => 0, 'recipients' => 0, 'baselined' => 0, 'errors' => 0, 'skipped' => 'quiet_hours'];
        }

        $default   = Settings::notificationsDefaultFrequency();
        $byUser    = [];   // user_id => ['name','email','locale','rows' => [...]]
        $baselined = 0;

        foreach (self::candidates($default) as $r) {
            $eff = $r['frequency'] ?? $default;

            // Sin línea base: anclarla a ahora sin avisar (evita inundar con histórico).
            if ($r['last_notified_at'] === null) {
                self::baseline($r, $nowStr);
                $baselined++;
                continue;
            }

            $u = &$byUser[$r['user_id']];
            $u['name']   = $r['name'];
            $u['email']  = $r['email'];
            $u['locale'] = $r['locale'] ?: Settings::defaultLocale();
            $u['rows'][] = ['eff' => $eff] + $r;
            unset($u);
        }

        $sent     = 0;
        $pushSent = 0;
        $errors   = 0;
        foreach ($byUser as $u) {
            // Reloj 'hourly' del usuario: su último aviso hourly = la marca de agua
            // más reciente entre sus formularios hourly. Debido si pasó ≥1 h (menos
            // la tolerancia al desfase del cron).
            $hourlyLast = null;
            foreach ($u['rows'] as $row) {
                if ($row['eff'] === 'hourly' && ($hourlyLast === null || $row['last_notified_at'] > $hourlyLast)) {
                    $hourlyLast = $row['last_notified_at'];
                }
            }
            $hourlyDue = $hourlyLast === null
                || ($now->getTimestamp() - strtotime($hourlyLast . ' UTC')) >= (self::HOURLY_INTERVAL - self::INTERVAL_SLACK);

            // Conteo por formulario en la ventana (marca de agua, ahora], con el
            // scoping por filas del usuario (admins sin filtro).
            $items = []; // [{form_name, count, nc}]
            foreach ($u['rows'] as $row) {
                if ($row['eff'] === 'hourly' && !$hourlyDue) continue;

                $scope = $row['role'] === 'admin'
                    ? null
                    : RowScope::normalize($row['row_filter'] ? json_decode($row['row_filter'], true) : null);
                [$scopeSql, $scopeP] = RowScope::sqlCondition($scope, 'json_payload');

                $cnt = (int) DB::run(
                    "SELECT COUNT(*) AS c FROM submissions_cache
                     WHERE form_id = ? AND submitted_at > ? AND submitted_at <= ? AND $scopeSql",
                    array_merge([$row['form_id'], $row['last_notified_at'], $nowStr], $scopeP)
                )->fetch()['c'];
                if ($cnt <= 0) continue;

                $items[] = ['form_name' => $row['form_name'], 'count' => $cnt, 'row' => $row];
            }
            if (!$items) continue;

            $delivered = false;

            if ($emailEnabled) {
                [$subject, $html, $text] = self::buildEmail($u['name'], $items, $u['locale']);
                if ($send($u['email'], $subject, $html, $text)) {
                    $sent++;
                    $delivered = true;
                } else {
                    $errors++;
                }
            }

            // Web Push a los dispositivos suscritos del usuario (opt-in por
            // dispositivo): mismo contenido — recuento + enlace, nunca el envío.
            if ($pushEnabled) {
                $userId  = $items[0]['row']['user_id'];
                $payload = self::buildPushPayload($items, $u['locale']);
                foreach (self::subscriptionsFor($userId) as $subRow) {
                    $status = $pushSend(
                        ['endpoint' => $subRow['endpoint'], 'p256dh' => $subRow['p256dh'], 'auth' => $subRow['auth']],
                        $payload
                    );
                    if ($status >= 200 && $status < 300) {
                        $pushSent++;
                        $delivered = true;
                        DB::run('UPDATE push_subscriptions SET last_used_at = ?, failed_count = 0 WHERE id = ?',
                            [$nowStr, $subRow['id']]);
                    } elseif ($status === 404 || $status === 410) {
                        // Suscripción muerta (dispositivo des-suscrito/caducado): poda.
                        DB::run('DELETE FROM push_subscriptions WHERE id = ?', [$subRow['id']]);
                    } else {
                        $errors++;
                        DB::run('UPDATE push_subscriptions SET failed_count = failed_count + 1 WHERE id = ?', [$subRow['id']]);
                        DB::run('DELETE FROM push_subscriptions WHERE id = ? AND failed_count >= ?',
                            [$subRow['id'], self::PUSH_FAIL_LIMIT]);
                    }
                }
            }

            // La marca de agua solo avanza en los formularios AVISADOS y solo si
            // ALGÚN canal entregó: si todo falló, se reintenta (agrupado) en la
            // siguiente pasada.
            if ($delivered) {
                foreach ($items as $it) {
                    self::advance($it['row'], $nowStr);
                }
            }
        }

        return ['sent' => $sent, 'push_sent' => $pushSent, 'recipients' => count($byUser), 'baselined' => $baselined, 'errors' => $errors];
    }

    /** Suscripciones push del usuario. */
    private static function subscriptionsFor(int $userId): array {
        return DB::run(
            'SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?',
            [$userId]
        )->fetchAll();
    }

    /**
     * Payload JSON del push (lo interpreta el service worker): título, cuerpo con el
     * recuento agrupado y URL de la app. Nunca contenido del envío.
     */
    public static function buildPushPayload(array $items, string $locale): string {
        $total = array_sum(array_column($items, 'count'));
        $en    = $locale === 'en';

        if (count($items) === 1) {
            $body = $en
                ? sprintf('%d new submission%s in "%s"', $total, $total === 1 ? '' : 's', $items[0]['form_name'])
                : sprintf('%d envío%s nuevo%s en «%s»', $total, $total === 1 ? '' : 's', $total === 1 ? '' : 's', $items[0]['form_name']);
        } else {
            $body = $en
                ? sprintf('%d new submissions in %d forms', $total, count($items))
                : sprintf('%d envíos nuevos en %d formularios', $total, count($items));
        }

        return json_encode([
            'title' => 'KoboManager',
            'body'  => $body,
            'url'   => rtrim(APP_URL, '/') . '/forms',
            'tag'   => 'km-new-submissions',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Pares usuario × formulario cuya frecuencia efectiva es hourly/every_sync:
     * viewers activos con can_view sobre formularios activos (con su row_filter) y
     * admins activos sobre todos los formularios activos (sin filtro). nc_id NULL =
     * suscrito por el default global sin fila propia todavía.
     */
    private static function candidates(string $default): array {
        $placeholders = implode(',', array_fill(0, count(self::LIVE_FREQUENCIES), '?'));

        $viewers = DB::run(
            "SELECT u.id AS user_id, u.name, u.email, u.locale, u.role,
                    f.id AS form_id, f.name AS form_name, p.row_filter,
                    nc.id AS nc_id, nc.frequency, nc.last_notified_at
             FROM users u
             JOIN user_form_permissions p ON p.user_id = u.id AND p.can_view = 1
             JOIN forms f ON f.id = p.form_id AND f.active = 1
             LEFT JOIN notification_config nc ON nc.user_id = u.id AND nc.form_id = f.id
             WHERE u.active = 1 AND u.role <> 'admin'
               AND COALESCE(nc.frequency, ?) IN ($placeholders)
             ORDER BY u.id, f.name",
            array_merge([$default], self::LIVE_FREQUENCIES)
        )->fetchAll();

        $admins = DB::run(
            "SELECT u.id AS user_id, u.name, u.email, u.locale, u.role,
                    f.id AS form_id, f.name AS form_name, NULL AS row_filter,
                    nc.id AS nc_id, nc.frequency, nc.last_notified_at
             FROM users u
             JOIN forms f ON f.active = 1
             LEFT JOIN notification_config nc ON nc.user_id = u.id AND nc.form_id = f.id
             WHERE u.active = 1 AND u.role = 'admin'
               AND COALESCE(nc.frequency, ?) IN ($placeholders)
             ORDER BY u.id, f.name",
            array_merge([$default], self::LIVE_FREQUENCIES)
        )->fetchAll();

        return array_merge($viewers, $admins);
    }

    /** Fija la línea base (marca de agua) de un par usuario × formulario. */
    private static function baseline(array $row, string $nowStr): void {
        if ($row['nc_id'] !== null) {
            DB::run('UPDATE notification_config SET last_notified_at = ? WHERE id = ?', [$nowStr, $row['nc_id']]);
            return;
        }
        // Suscrito por el default global sin fila propia: se crea con frequency NULL
        // (sigue heredando el default) solo para sostener la marca de agua.
        DB::run(
            'INSERT INTO notification_config (user_id, form_id, frequency, last_notified_at)
             VALUES (?, ?, NULL, ?)',
            [$row['user_id'], $row['form_id'], $nowStr]
        );
    }

    /** Avanza la marca de agua tras un aviso enviado. */
    private static function advance(array $row, string $nowStr): void {
        DB::run('UPDATE notification_config SET last_notified_at = ? WHERE id = ?', [$nowStr, $row['nc_id']]);
    }

    /**
     * ¿Cae `$nowUtc` dentro del tramo de silencio [start, end) expresado en hora
     * local de APP_TIMEZONE? El tramo puede cruzar la medianoche (22:00 → 07:00).
     */
    public static function inQuietHours(DateTimeImmutable $nowUtc, string $start, string $end, ?string $tz = null): bool {
        $tzId  = $tz ?? (defined('APP_TIMEZONE') && APP_TIMEZONE !== '' ? APP_TIMEZONE : 'UTC');
        try {
            $local = $nowUtc->setTimezone(new DateTimeZone($tzId));
        } catch (Throwable) {
            $local = $nowUtc; // zona mal configurada: mejor avisar que callar
        }
        $m = ((int) $local->format('H')) * 60 + (int) $local->format('i');
        $s = self::minutes($start);
        $e = self::minutes($end);
        return $s < $e ? ($m >= $s && $m < $e) : ($m >= $s || $m < $e);
    }

    private static function minutes(string $hhmm): int {
        [$h, $i] = explode(':', $hhmm);
        return ((int) $h) * 60 + (int) $i;
    }

    /**
     * Construye [asunto, html, texto] del aviso agrupado, en es|en. Solo recuentos y
     * un enlace a la app: nunca contenido del envío.
     *
     * @param array $items [{form_name, count}, ...]
     */
    public static function buildEmail(string $name, array $items, string $locale): array {
        $total = array_sum(array_column($items, 'count'));
        $url   = rtrim(APP_URL, '/') . '/forms';
        $en    = $locale === 'en';

        $subject = $en
            ? sprintf('[KoboManager] %d new submission%s', $total, $total === 1 ? '' : 's')
            : sprintf('[KoboManager] %d envío%s nuevo%s', $total, $total === 1 ? '' : 's', $total === 1 ? '' : 's');

        $linesText = '';
        $linesHtml = '';
        foreach ($items as $it) {
            $safe = htmlspecialchars($it['form_name'], ENT_QUOTES, 'UTF-8');
            if ($en) {
                $linesText .= sprintf("  • %d new submission%s in \"%s\"\n", $it['count'], $it['count'] === 1 ? '' : 's', $it['form_name']);
                $linesHtml .= sprintf('<li>%d new submission%s in <strong>%s</strong></li>', $it['count'], $it['count'] === 1 ? '' : 's', $safe);
            } else {
                $linesText .= sprintf("  • %d envío%s nuevo%s en \"%s\"\n", $it['count'], $it['count'] === 1 ? '' : 's', $it['count'] === 1 ? '' : 's', $it['form_name']);
                $linesHtml .= sprintf('<li>%d envío%s nuevo%s en <strong>%s</strong></li>', $it['count'], $it['count'] === 1 ? '' : 's', $it['count'] === 1 ? '' : 's', $safe);
            }
        }

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        if ($en) {
            $text = "Hi $name,\n\nNew submissions since your last notice:\n\n$linesText\n"
                  . "Open the app to review them: $url\n\n"
                  . "---\nTo change these notices, go to Notifications in the app.\n";
            $html = "<p>Hi $safeName,</p>"
                  . "<p>New submissions since your last notice:</p>"
                  . "<ul>$linesHtml</ul>"
                  . "<p><a href=\"$url\">Open the app to review them</a></p>"
                  . "<hr><p style=\"color:#888;font-size:12px\">To change these notices, go to Notifications in the app.</p>";
        } else {
            $text = "Hola $name,\n\nEnvíos nuevos desde tu último aviso:\n\n$linesText\n"
                  . "Accede a la app para revisarlos: $url\n\n"
                  . "---\nPara cambiar estos avisos, ve a Notificaciones en la app.\n";
            $html = "<p>Hola $safeName,</p>"
                  . "<p>Envíos nuevos desde tu último aviso:</p>"
                  . "<ul>$linesHtml</ul>"
                  . "<p><a href=\"$url\">Accede a la app para revisarlos</a></p>"
                  . "<hr><p style=\"color:#888;font-size:12px\">Para cambiar estos avisos, ve a Notificaciones en la app.</p>";
        }

        return [$subject, $html, $text];
    }
}
