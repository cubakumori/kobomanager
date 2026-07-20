<?php
/**
 * POST /api/v1/auth/login
 * Body: { email, password }
 * Verifica credenciales y crea sesión + JWT en cookie HttpOnly… salvo que el
 * usuario tenga 2FA activo: entonces NO emite cookie y responde
 * { totp_required: true, challenge } — el reto (5 min) se canjea junto al código
 * TOTP en POST auth/login/totp, que es quien emite la sesión.
 */

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Rate limiting: máx. 5 intentos fallidos por IP por minuto.
if (RateLimit::tooMany($ip, 5, 60)) {
    ErrorResponse::send('AUTH_RATE_LIMITED');
}

$in = Request::required(['email', 'password']);

$user = DB::run(
    'SELECT id, name, email, role, locale, ui_prefs, password_hash, totp_enabled_at, active
     FROM users WHERE email = ?',
    [$in['email']]
)->fetch();

// Mensaje genérico para no revelar si el email existe — y TIEMPO también genérico:
// con un email inexistente/inactivo se verifica contra un hash señuelo para que el
// coste bcrypt sea el mismo y la latencia no delate si la cuenta existe.
$decoyHash = '$2y$12$JCp9mYWrseAdiKrep2I4jOSsb8yF28gsf4DocX1D85/xTc5Wyy/aK';
$knownUser = $user && $user['active'];
$passOk    = password_verify($in['password'], $knownUser ? $user['password_hash'] : $decoyHash);
if (!$knownUser || !$passOk) {
    RateLimit::hit($ip);
    ErrorResponse::send('VALIDATION_ERROR', 'Credenciales incorrectas', 401);
}

// Con 2FA activo las credenciales solas NO abren sesión: se responde un reto de
// corta vida y el segundo paso (auth/login/totp) emite la cookie. Los intentos
// fallidos de esta IP no se limpian todavía (el login aún no está completo).
if ($user['totp_enabled_at'] !== null) {
    ErrorResponse::ok([
        'totp_required' => true,
        'challenge'     => Auth::totpChallenge((int) $user['id']),
    ]);
}

// Login correcto: limpia los intentos de esta IP y emite la sesión.
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
    'totp_enabled' => false,
    // Si la política global exige 2FA a este usuario y no lo tiene, el frontend
    // lo lleva directo a la pantalla de activación (la API ya está cortada).
    'totp_enroll_required' => Auth::totpPolicyApplies(['role' => $user['role']]),
]);
