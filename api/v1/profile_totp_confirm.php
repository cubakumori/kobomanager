<?php
/**
 * POST /api/v1/profile/totp/confirm   (usuario autenticado)
 * Body: { code }
 *
 * Confirma el enrolamiento pendiente con un código TOTP válido del secreto
 * recién escaneado → el 2FA queda ACTIVO y se devuelven los CÓDIGOS DE
 * RECUPERACIÓN en claro UNA SOLA VEZ (en BD solo quedan sus hashes; cada uno
 * vale un único uso en el login).
 */

$user = Auth::require();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$row = DB::run(
    'SELECT totp_secret, totp_enabled_at FROM users WHERE id = ?',
    [$user['id']]
)->fetch();
if ($row['totp_enabled_at'] !== null) {
    ErrorResponse::send('VALIDATION_ERROR', 'El 2FA ya está activo');
}
if (empty($row['totp_secret'])) {
    ErrorResponse::send('VALIDATION_ERROR', 'No hay enrolamiento pendiente; genera primero el código QR');
}

$in   = Request::required(['code']);
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (RateLimit::tooManyBucket($ip, 'totp', 5, 60)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}

$step = Totp::verify(TokenVault::decrypt($row['totp_secret']), trim((string) $in['code']));
if ($step === null) {
    RateLimit::hitBucket($ip, 'totp');
    ErrorResponse::send('TOTP_INVALID');
}

// Códigos de recuperación: 8, formato XXXXX-XXXXX (alfabeto base32, legible al
// dictar). En BD solo sus hashes; el frontend los muestra una única vez.
$plain  = [];
$hashes = [];
for ($i = 0; $i < 8; $i++) {
    $raw = Totp::base32Encode(random_bytes(7));           // ~11 chars base32
    $c   = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    $plain[]  = $c;
    $hashes[] = password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT);
}

DB::run(
    'UPDATE users SET totp_enabled_at = NOW(), totp_recovery_codes = ?, totp_last_step = ? WHERE id = ?',
    [json_encode($hashes), $step, $user['id']]
);
Audit::log($user['id'], 'totp_enable', null, null, null);

ErrorResponse::ok(['enabled' => true, 'recovery_codes' => $plain]);
