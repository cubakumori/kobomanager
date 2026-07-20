<?php
/**
 * /api/v1/profile/totp   (usuario autenticado, su propio 2FA)
 *
 *   GET    → estado: { enabled, pending, recovery_left, required_by_policy }.
 *   DELETE → desactiva el 2FA. Con enrolamiento PENDIENTE (secreto sin confirmar)
 *            se cancela sin más; ACTIVO exige un código TOTP vigente en el body
 *            ({ code }) y se rechaza si la política global lo exige para su rol.
 *
 * El alta vive en profile/totp/init (genera el secreto) y profile/totp/confirm
 * (lo confirma con un código y entrega los códigos de recuperación).
 */

$user   = Auth::require();
$method = Request::method();

$row = DB::run(
    'SELECT totp_secret, totp_enabled_at, totp_recovery_codes FROM users WHERE id = ?',
    [$user['id']]
)->fetch();
$enabled = $row['totp_enabled_at'] !== null;
$pending = !$enabled && !empty($row['totp_secret']);

if ($method === 'GET') {
    ErrorResponse::ok([
        'enabled'            => $enabled,
        'pending'            => $pending,
        'recovery_left'      => $enabled ? count(json_decode((string) $row['totp_recovery_codes'], true) ?: []) : 0,
        'required_by_policy' => Auth::totpPolicyApplies($user),
    ]);
}

if ($method !== 'DELETE') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

// Cancelar un enrolamiento pendiente no pide código: aún no protege nada.
if (!$enabled) {
    DB::run(
        'UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL,
                totp_recovery_codes = NULL, totp_last_step = NULL WHERE id = ?',
        [$user['id']]
    );
    ErrorResponse::ok(['enabled' => false]);
}

// Desactivar el 2FA activo: prohibido si la política lo exige para este usuario,
// y exige demostrar posesión del factor (código TOTP vigente, con anti-replay).
if (Auth::totpPolicyApplies($user)) {
    ErrorResponse::send('AUTH_INSUFFICIENT_PERMISSIONS', 'La política de esta instancia exige 2FA para tu cuenta; no puedes desactivarlo');
}
$body = Request::json();
$code = trim((string) ($body['code'] ?? ''));
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (RateLimit::tooManyBucket($ip, 'totp', 5, 60)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}
$last = DB::run('SELECT totp_last_step FROM users WHERE id = ?', [$user['id']])->fetch()['totp_last_step'];
$step = Totp::verify(TokenVault::decrypt($row['totp_secret']), $code);
if ($step === null || ($last !== null && $step <= (int) $last)) {
    RateLimit::hitBucket($ip, 'totp');
    ErrorResponse::send('TOTP_INVALID');
}

DB::run(
    'UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL,
            totp_recovery_codes = NULL, totp_last_step = NULL WHERE id = ?',
    [$user['id']]
);
Audit::log($user['id'], 'totp_disable', null, null, null);
ErrorResponse::ok(['enabled' => false]);
