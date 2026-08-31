<?php
/**
 * /api/v1/notifications   (usuario autenticado, configura lo suyo)
 *   GET → formularios visibles para el usuario, con su frecuencia de aviso EFECTIVA
 *         (off|daily|hourly|every_sync): la explícita si existe o, en su ausencia,
 *         el valor por defecto global (notifications_default_frequency).
 *   PUT → { frequencies: { <form_id>: 'off'|'daily'|'hourly'|'every_sync', ... } }
 *         guarda la frecuencia por formulario.
 *
 * El PUT borra las filas del usuario y las reinserta (transaccional; la clave
 * única (user_id, form_id) de 1.53.0 hace además imposible el duplicado ante
 * carreras con el notificador). La marca de agua de los avisos casi inmediatos
 * (last_notified_at + last_notified_id, ver lib/Notifier) se CONSERVA al
 * reinsertar; al pasar un formulario a hourly/every_sync desde un estado no-vivo
 * se inicializa a «ahora» para no avisar del histórico acumulado.
 */

$user = Auth::require();

/** Frecuencias con marca de agua (avisos tras cada sync). */
const LIVE_FREQUENCIES = ['hourly', 'every_sync'];

/** IDs de formularios que el usuario puede ver (admin = todos los activos). */
function visible_form_ids(array $user): array {
    if ($user['role'] === 'admin') {
        $rows = DB::run('SELECT id FROM forms WHERE active = 1')->fetchAll();
    } else {
        $rows = DB::run(
            'SELECT f.id
             FROM forms f
             JOIN user_form_permissions p ON p.form_id = f.id AND p.user_id = ? AND p.can_view = 1
             WHERE f.active = 1',
            [$user['id']]
        )->fetchAll();
    }
    return array_map(fn($r) => (int) $r['id'], $rows);
}

if (Request::method() === 'GET') {
    // Preferencia EXPLÍCITA del usuario por formulario. frequency NULL (o fila
    // ausente) significa «sin preferencia» → se aplica el valor por defecto global.
    $rows = DB::run(
        'SELECT form_id, frequency FROM notification_config WHERE user_id = ?',
        [$user['id']]
    )->fetchAll();
    $explicit = [];
    foreach ($rows as $r) {
        if ($r['frequency'] !== null) {
            $explicit[(int) $r['form_id']] = $r['frequency'];
        }
    }
    $default = Settings::notificationsDefaultFrequency();

    if ($user['role'] === 'admin') {
        $forms = DB::run(
            'SELECT f.id, f.name, a.label AS account_label
             FROM forms f JOIN kobo_accounts a ON a.id = f.kobo_account_id
             WHERE f.active = 1 ORDER BY a.label, f.name'
        )->fetchAll();
    } else {
        $forms = DB::run(
            'SELECT f.id, f.name, a.label AS account_label
             FROM forms f
             JOIN kobo_accounts a ON a.id = f.kobo_account_id
             JOIN user_form_permissions p ON p.form_id = f.id AND p.user_id = ? AND p.can_view = 1
             WHERE f.active = 1 ORDER BY a.label, f.name',
            [$user['id']]
        )->fetchAll();
    }

    $out = array_map(fn($f) => [
        'form_id'       => (int) $f['id'],
        'name'          => $f['name'],
        'account_label' => $f['account_label'],
        // Efectiva = preferencia explícita si existe; si no, el valor por defecto.
        'frequency'     => $explicit[(int) $f['id']] ?? $default,
    ], $forms);

    ErrorResponse::ok([
        'forms'             => $out,
        'default_frequency' => $default,
        'valid_frequencies' => Settings::VALID_NOTIFY_FREQUENCIES,
        'quiet'             => Settings::notificationsQuiet(),
    ]);
}

if (Request::method() === 'PUT') {
    $freqs = Request::json()['frequencies'] ?? [];
    if (!is_array($freqs)) {
        ErrorResponse::send('VALIDATION_ERROR', 'frequencies debe ser un mapa form_id → frecuencia');
    }
    foreach ($freqs as $v) {
        if (!in_array($v, Settings::VALID_NOTIFY_FREQUENCIES, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Frecuencia de notificación no válida');
        }
    }

    // Se guarda una preferencia EXPLÍCITA para cada formulario visible presente en el
    // mapa (la UI manda todos), de modo que la elección persista frente al valor por
    // defecto global. Los visibles ausentes del mapa quedan «sin preferencia»
    // (frequency NULL = heredan el default); los no visibles ahora (p. ej. nuevos)
    // también lo heredarán.
    $visible = visible_form_ids($user);
    $default = Settings::notificationsDefaultFrequency();
    $nowUtc  = gmdate('Y-m-d H:i:s');

    // Estado anterior, para conservar la marca de agua de los avisos casi inmediatos.
    $old = [];
    foreach (DB::run(
        'SELECT form_id, frequency, last_notified_at, last_notified_id FROM notification_config WHERE user_id = ?',
        [$user['id']]
    )->fetchAll() as $r) {
        $old[(int) $r['form_id']] = $r;
    }
    // Tope actual de la caché: ancla de la marca por id al entrar en una frecuencia viva.
    $maxCacheId = (int) (DB::run('SELECT MAX(id) AS m FROM submissions_cache')->fetch()['m'] ?? 0);

    $pdo = DB::conn();
    $pdo->beginTransaction();
    try {
        DB::run('DELETE FROM notification_config WHERE user_id = ?', [$user['id']]);
        foreach ($visible as $formId) {
            $newFreq = array_key_exists($formId, $freqs) ? $freqs[$formId]
                     : (array_key_exists((string) $formId, $freqs) ? $freqs[(string) $formId] : null);

            $oldRow       = $old[$formId] ?? null;
            $oldEffective = ($oldRow['frequency'] ?? null) ?? $default;
            $newEffective = $newFreq ?? $default;

            // Marca de agua (fecha + id de fila): se conserva si el formulario ya
            // estaba en una frecuencia «viva»; al ENTRAR en una viva se ancla a ahora
            // (no avisar del histórico).
            $watermark   = $oldRow['last_notified_at'] ?? null;
            $watermarkId = $oldRow['last_notified_id'] ?? null;
            if (in_array($newEffective, LIVE_FREQUENCIES, true)
                && (!in_array($oldEffective, LIVE_FREQUENCIES, true) || $watermark === null)) {
                $watermark   = $nowUtc;
                $watermarkId = $maxCacheId;
            }

            DB::run(
                'INSERT INTO notification_config (user_id, form_id, frequency, last_notified_at, last_notified_id)
                 VALUES (?, ?, ?, ?, ?)',
                [$user['id'], $formId, $newFreq, $watermark, $watermarkId]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $saved = [];
    foreach ($visible as $formId) {
        $saved[$formId] = $freqs[$formId] ?? $freqs[(string) $formId] ?? $default;
    }
    ErrorResponse::ok(['frequencies' => $saved]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
