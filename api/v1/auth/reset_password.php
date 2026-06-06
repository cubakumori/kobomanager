<?php
/**
 * /api/v1/auth/reset-password
 *
 *   GET  ?token=...           → { valid: bool }   (para que el frontend muestre o no el formulario)
 *   POST { token, password }  → fija la nueva contraseña, consume el token e
 *                               invalida TODAS las sesiones activas del usuario.
 *
 * El token recibido se compara por su hash (sha256) contra password_resets.
 * Un token es válido si existe, no se ha usado (used_at IS NULL) y no ha expirado.
 * Si el flujo está deshabilitado, todo token se considera inválido.
 */

if (!in_array(Request::method(), ['GET', 'POST'], true)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

/** Busca la fila de reset válida para un token en claro, o null. */
function find_valid_reset(string $token): ?array {
    if ($token === '' || !Settings::passwordResetEnabled()) {
        return null;
    }
    $hash = hash('sha256', $token);
    $row = DB::run(
        'SELECT id, user_id FROM password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
        [$hash]
    )->fetch();
    return $row ?: null;
}

// --- GET: comprobar validez del token ---
if (Request::method() === 'GET') {
    $token = (string) ($_GET['token'] ?? '');
    ErrorResponse::ok(['valid' => find_valid_reset($token) !== null]);
}

// --- POST: fijar nueva contraseña ---
$in       = Request::required(['token', 'password']);
$token    = (string) $in['token'];
$password = (string) $in['password'];

if (strlen($password) < 8) {
    ErrorResponse::send('VALIDATION_ERROR', 'La contraseña debe tener al menos 8 caracteres');
}

$reset = find_valid_reset($token);
if ($reset === null) {
    ErrorResponse::send('RESET_TOKEN_INVALID');
}

$userId = (int) $reset['user_id'];
$hash   = password_hash($password, PASSWORD_DEFAULT);

// Aplicar el cambio de forma atómica: nueva contraseña + consumir token + cerrar sesiones.
$pdo = DB::conn();
$pdo->beginTransaction();
try {
    DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $userId]);
    DB::run('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$reset['id']]);
    // Invalidar el resto de tokens del usuario por si hubiera alguno.
    DB::run('DELETE FROM password_resets WHERE user_id = ? AND id <> ?', [$userId, $reset['id']]);
    // Cerrar todas las sesiones activas (forzar re-login con la nueva contraseña).
    DB::run('DELETE FROM user_sessions WHERE user_id = ?', [$userId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

Audit::log($userId, 'password_reset_completed', null, null, null);

ErrorResponse::ok(['message' => 'ok']);
