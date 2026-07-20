<?php
/**
 * POST /api/v1/auth/login/totp
 * Body: { challenge, code }
 *
 * Segundo paso del login con 2FA: canjea el reto emitido por auth/login (JWT de
 * corta vida, claim p='totp') junto a un código TOTP de 6 dígitos O un código de
 * recuperación (formato XXXXX-XXXXX, de un solo uso). Solo aquí se emite la
 * cookie de sesión.
 *
 * Defensas: rate-limit por IP con bucket propio ('totp'), anti-replay de TOTP
 * (el paso de tiempo aceptado debe ser mayor que users.totp_last_step) y los
 * códigos de recuperación se guardan como hashes y se eliminan al usarse.
 */

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (RateLimit::tooManyBucket($ip, 'totp', 5, 60)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}

$in = Request::required(['challenge', 'code']);

$userId = Auth::totpChallengeUserId((string) $in['challenge']);
if ($userId === null) {
    // Reto inválido o caducado: el frontend vuelve al paso de credenciales.
    ErrorResponse::send('TOTP_CHALLENGE_EXPIRED');
}

$user = DB::run(
    'SELECT id, name, email, role, locale, ui_prefs, active,
            totp_secret, totp_enabled_at, totp_recovery_codes, totp_last_step
     FROM users WHERE id = ? AND active = 1',
    [$userId]
)->fetch();
if (!$user || $user['totp_enabled_at'] === null || !$user['totp_secret']) {
    ErrorResponse::send('TOTP_CHALLENGE_EXPIRED');
}

$code = trim((string) $in['code']);

// ¿Código de recuperación? (más largo que un TOTP y no solo dígitos). Se compara
// contra los hashes; el usado se elimina (un solo uso).
$normalized = strtoupper(preg_replace('/[\s-]+/', '', $code));
$isRecovery = strlen($normalized) > Totp::DIGITS;

if ($isRecovery) {
    $hashes = json_decode((string) $user['totp_recovery_codes'], true) ?: [];
    $matched = null;
    foreach ($hashes as $i => $hash) {
        if (password_verify($normalized, $hash)) { $matched = $i; break; }
    }
    if ($matched === null) {
        RateLimit::hitBucket($ip, 'totp');
        ErrorResponse::send('TOTP_INVALID');
    }
    unset($hashes[$matched]);
    DB::run(
        'UPDATE users SET totp_recovery_codes = ? WHERE id = ?',
        [json_encode(array_values($hashes)), $user['id']]
    );
    Audit::log((int) $user['id'], 'totp_recovery_used', null, null, ['remaining' => count($hashes)]);
} else {
    $secret = TokenVault::decrypt($user['totp_secret']);
    $step   = Totp::verify($secret, $code);
    // Anti-replay: el paso aceptado debe avanzar respecto al último usado.
    if ($step === null || ($user['totp_last_step'] !== null && $step <= (int) $user['totp_last_step'])) {
        RateLimit::hitBucket($ip, 'totp');
        ErrorResponse::send('TOTP_INVALID');
    }
    DB::run('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $user['id']]);
}

// Login completo: ahora sí se limpian los fallos de credenciales y se emite sesión.
RateLimit::clear($ip);
Auth::issue($user);

ErrorResponse::ok([
    'id'          => (int) $user['id'],
    'name'        => $user['name'],
    'email'       => $user['email'],
    'role'        => $user['role'],
    'locale_pref' => $user['locale'] ?? null,
    'locale'      => ($user['locale'] ?? null) ?: Settings::defaultLocale(),
    'ui_prefs'    => $user['ui_prefs'] ? (json_decode($user['ui_prefs'], true) ?: null) : null,
    'audit_self_view_enabled' => Settings::auditSelfViewEnabled(),
    'totp_enabled' => true,
    'totp_enroll_required' => false,
]);
