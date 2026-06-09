<?php
/**
 * POST /api/v1/public/contact   (PÚBLICO, sin sesión)
 * Body: { name, email, message, org?, topic? }
 *
 * Formulario de contacto de la página «Apoyar». Cada mensaje se GUARDA en
 * `contact_messages` (fuente de verdad; nada se pierde aunque el email falle) y,
 * además, se intenta una notificación por email best-effort a CONTACT_TO con el
 * Reply-To del visitante. La respuesta es siempre genérica (ok) si se guardó.
 *
 * Rate-limited por IP (bucket 'contact', tabla rate_hits): freno anti-spam.
 */

require_once __DIR__ . '/../../lib/Mailer.php';

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Anti-spam: máx. 5 envíos por IP cada hora.
if (RateLimit::tooManyBucket($ip, 'contact', 5, 3600)) {
    ErrorResponse::send('RATE_LIMITED');
}
RateLimit::hitBucket($ip, 'contact');

$in      = Request::required(['name', 'email', 'message']);
$name    = mb_substr($in['name'], 0, 120);
$email   = mb_substr($in['email'], 0, 255);
$message = mb_substr($in['message'], 0, 5000);

$body  = Request::json();
$org   = isset($body['org']) && is_string($body['org']) ? mb_substr(trim($body['org']), 0, 160) : null;
$topic = isset($body['topic']) && is_string($body['topic']) ? $body['topic'] : 'general';
if (!in_array($topic, ['general', 'hire', 'proposal', 'using'], true)) {
    $topic = 'general';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Email no válido');
}

// 1) Persistir (fuente de verdad).
DB::run(
    'INSERT INTO contact_messages (name, email, org, topic, message, ip) VALUES (?, ?, ?, ?, ?, ?)',
    [$name, $email, ($org !== '' ? $org : null), $topic, $message, $ip]
);
$id = (int) DB::conn()->lastInsertId();

// 2) Notificación por email best-effort (no bloquea la respuesta si falla).
$contactTo = defined('CONTACT_TO') ? CONTACT_TO : '';
$emailed   = false;
if ($contactTo !== '') {
    [$subject, $html, $text] = build_contact_email($name, $email, $org, $topic, $message, $ip);
    $emailed = Mailer::send($contactTo, $subject, $html, $text, $email);
    if ($emailed) {
        DB::run('UPDATE contact_messages SET emailed = 1 WHERE id = ?', [$id]);
    }
}

ErrorResponse::ok(['message' => 'ok']);

/** Construye [asunto, html, texto] de la notificación interna (en español, para el destinatario). */
function build_contact_email(string $name, string $email, ?string $org, string $topic, string $message, string $ip): array {
    $topics = [
        'general'  => 'Consulta general',
        'hire'     => 'Contratar un servicio',
        'proposal' => 'Propuesta sobre la app',
        'using'    => 'Organización que usa la app',
    ];
    $topicLabel = $topics[$topic] ?? $topics['general'];

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeMail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeOrg  = $org ? htmlspecialchars($org, ENT_QUOTES, 'UTF-8') : '—';
    $safeMsg  = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $safeIp   = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');

    $subject = "[KoboManager] Contacto: $topicLabel — $name";

    $text = "Nuevo mensaje desde la página «Apoyar» de KoboManager.\n\n"
          . "Asunto: $topicLabel\n"
          . "Nombre: $name\n"
          . "Email: $email\n"
          . "Organización: " . ($org ?: '—') . "\n"
          . "IP: $ip\n\n"
          . "Mensaje:\n$message\n";

    $html = "<p>Nuevo mensaje desde la página <strong>«Apoyar»</strong> de KoboManager.</p>"
          . "<table style=\"border-collapse:collapse;font-size:14px\">"
          . "<tr><td style=\"padding:2px 10px 2px 0;color:#666\">Asunto</td><td><strong>$topicLabel</strong></td></tr>"
          . "<tr><td style=\"padding:2px 10px 2px 0;color:#666\">Nombre</td><td>$safeName</td></tr>"
          . "<tr><td style=\"padding:2px 10px 2px 0;color:#666\">Email</td><td><a href=\"mailto:$safeMail\">$safeMail</a></td></tr>"
          . "<tr><td style=\"padding:2px 10px 2px 0;color:#666\">Organización</td><td>$safeOrg</td></tr>"
          . "<tr><td style=\"padding:2px 10px 2px 0;color:#666\">IP</td><td>$safeIp</td></tr>"
          . "</table>"
          . "<p style=\"margin-top:14px\"><strong>Mensaje:</strong></p>"
          . "<p style=\"white-space:pre-wrap\">$safeMsg</p>"
          . "<hr><p style=\"color:#888;font-size:12px\">Responde a este correo para contestar directamente al remitente.</p>";

    return [$subject, $html, $text];
}
