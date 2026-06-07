<?php
/**
 * /api/v1/profile/sessions   (usuario autenticado, sus PROPIAS sesiones)
 *   GET    → sesiones activas del propio usuario; marca la actual con `current`.
 *   DELETE → cierra todas las DEMÁS sesiones (mantiene la actual). Revoca sus
 *            JWT: el front controller valida que el `jti` siga existiendo.
 *
 * Equivalente de autoservicio del cierre remoto que el admin hace en
 * /admin/users/{id}/sessions, pero sin desconectar el dispositivo en uso
 * (cerrar la sesión actual = logout normal, que ya existe).
 */

$user = Auth::require();
$jti  = Auth::currentTokenId();

if (Request::method() === 'GET') {
    $rows = DB::run(
        'SELECT token_id, ip, user_agent, last_activity, created_at, expires_at
         FROM user_sessions
         WHERE user_id = ? AND expires_at > NOW()
         ORDER BY last_activity DESC',
        [$user['id']]
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'current'       => $jti !== null && hash_equals($r['token_id'], $jti),
            'ip'            => $r['ip'],
            'user_agent'    => $r['user_agent'],
            'last_activity' => $r['last_activity'],
            'created_at'    => $r['created_at'],
            'expires_at'    => $r['expires_at'],
        ];
    }
    ErrorResponse::ok($out);
}

if (Request::method() === 'DELETE') {
    // Cierra todas las sesiones del usuario salvo la actual.
    if ($jti !== null) {
        $stmt = DB::run(
            'DELETE FROM user_sessions WHERE user_id = ? AND token_id <> ?',
            [$user['id'], $jti]
        );
    } else {
        $stmt = DB::run('DELETE FROM user_sessions WHERE user_id = ?', [$user['id']]);
    }
    $closed = $stmt->rowCount();
    Audit::log($user['id'], 'revoke_own_sessions', null, null, ['closed' => $closed]);
    ErrorResponse::ok(['closed' => $closed]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
