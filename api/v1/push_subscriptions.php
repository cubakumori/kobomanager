<?php
/**
 * /api/v1/push/subscriptions   (usuario autenticado, gestiona SUS dispositivos)
 *   GET    → dispositivos suscritos del usuario (sin claves; el endpoint solo como
 *            hash, suficiente para que el cliente marque «este dispositivo»).
 *   POST   → { endpoint, keys: { p256dh, auth }, label? }  alta/refresco (upsert por
 *            endpoint) de la suscripción del navegador actual. Requiere claves VAPID
 *            configuradas.
 *   DELETE → { endpoint } o { id }  baja: por endpoint (el propio dispositivo) o por
 *            id (quitar otro dispositivo de la lista, siempre del propio usuario).
 *
 * El opt-in es POR DISPOSITIVO (cada navegador genera su suscripción); qué se avisa y
 * cuándo lo decide la misma frecuencia por formulario del email (ver lib/Notifier).
 */

$user = Auth::require();

if (Request::method() === 'GET') {
    $rows = DB::run(
        'SELECT id, endpoint_hash, ua_label, failed_count, created_at, last_used_at
         FROM push_subscriptions WHERE user_id = ? ORDER BY created_at DESC',
        [$user['id']]
    )->fetchAll();
    ErrorResponse::ok([
        'configured'    => WebPush::configured(),
        'subscriptions' => array_map(fn($r) => [
            'id'            => (int) $r['id'],
            'endpoint_hash' => $r['endpoint_hash'],
            'label'         => $r['ua_label'],
            'created_at'    => $r['created_at'],
            'last_used_at'  => $r['last_used_at'],
        ], $rows),
    ]);
}

if (Request::method() === 'POST') {
    if (!WebPush::configured()) {
        ErrorResponse::send('VALIDATION_ERROR', 'Web Push no está configurado en el servidor (claves VAPID)', 409);
    }
    $in       = Request::json();
    $endpoint = (string) ($in['endpoint'] ?? '');
    $p256dh   = (string) ($in['keys']['p256dh'] ?? '');
    $auth     = (string) ($in['keys']['auth'] ?? '');
    $label    = trim((string) ($in['label'] ?? ''));

    // Validación: el endpoint debe ser una URL https del push service; las claves,
    // base64url del tamaño correcto (punto P-256 de 65 B y secreto de 16 B).
    if (!preg_match('#^https://[^\s]+$#', $endpoint) || strlen($endpoint) > 2000) {
        ErrorResponse::send('VALIDATION_ERROR', 'endpoint no válido');
    }
    try {
        if (strlen(WebPush::b64uDecode($p256dh)) !== 65 || strlen(WebPush::b64uDecode($auth)) !== 16) {
            throw new InvalidArgumentException();
        }
    } catch (InvalidArgumentException) {
        ErrorResponse::send('VALIDATION_ERROR', 'Claves de suscripción no válidas');
    }
    if (mb_strlen($label) > 160) {
        $label = mb_substr($label, 0, 160);
    }

    $hash = hash('sha256', $endpoint);
    // Upsert por endpoint: si el navegador re-suscribe (claves rotadas) se refresca la
    // fila; si el endpoint estaba en otro usuario (sesión distinta en el mismo
    // navegador), pasa al usuario actual — el dispositivo solo puede avisar a quien
    // tiene la sesión.
    DB::run(
        'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, ua_label)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint),
             p256dh = VALUES(p256dh), auth = VALUES(auth), ua_label = VALUES(ua_label),
             failed_count = 0',
        [$user['id'], $endpoint, $hash, $p256dh, $auth, $label !== '' ? $label : null]
    );
    Audit::log((int) $user['id'], 'push_subscribe', null, null, ['label' => $label]);
    ErrorResponse::ok(['endpoint_hash' => $hash]);
}

if (Request::method() === 'DELETE') {
    $in = Request::json();
    if (!empty($in['endpoint'])) {
        DB::run(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?',
            [$user['id'], hash('sha256', (string) $in['endpoint'])]
        );
    } elseif (!empty($in['id'])) {
        DB::run(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND id = ?',
            [$user['id'], (int) $in['id']]
        );
    } else {
        ErrorResponse::send('VALIDATION_ERROR', 'Falta endpoint o id');
    }
    Audit::log((int) $user['id'], 'push_unsubscribe', null, null, []);
    ErrorResponse::ok(['removed' => true]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
