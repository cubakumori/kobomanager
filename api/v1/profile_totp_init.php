<?php
/**
 * POST /api/v1/profile/totp/init   (usuario autenticado)
 *
 * Arranca (o reinicia) el enrolamiento 2FA: genera un secreto TOTP nuevo, lo
 * guarda CIFRADO (TokenVault) con la activación pendiente (totp_enabled_at NULL)
 * y devuelve el secreto en base32 + el URI otpauth:// para el QR. No toca un 2FA
 * ya ACTIVO: para regenerarlo hay que desactivarlo antes (con código).
 */

$user = Auth::require();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$row = DB::run('SELECT totp_enabled_at FROM users WHERE id = ?', [$user['id']])->fetch();
if ($row['totp_enabled_at'] !== null) {
    ErrorResponse::send('VALIDATION_ERROR', 'El 2FA ya está activo; desactívalo antes de re-enrolar');
}

$secret = Totp::generateSecret();
DB::run(
    'UPDATE users SET totp_secret = ?, totp_enabled_at = NULL,
            totp_recovery_codes = NULL, totp_last_step = NULL WHERE id = ?',
    [TokenVault::encrypt($secret), $user['id']]
);

ErrorResponse::ok([
    'secret'      => $secret,
    'otpauth_uri' => Totp::otpauthUri($secret, $user['email']),
]);
