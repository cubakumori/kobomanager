<?php
/**
 * /api/v1/admin/users/{id}/sessions   (solo admin)
 *   GET    → sesiones activas del usuario (para inspección).
 *   DELETE → cierra TODAS las sesiones del usuario (revoca sus JWT: el front
 *            controller valida que el `jti` siga existiendo en user_sessions).
 *
 * Útil para forzar el cierre de sesión de un usuario en remoto sin desactivarlo.
 */

$admin = Auth::requireAdmin();
$id    = (int) Request::param('id');

$user = DB::run('SELECT id, name FROM users WHERE id = ?', [$id])->fetch();
if (!$user) {
    ErrorResponse::send('NOT_FOUND', 'Usuario no encontrado');
}

if (Request::method() === 'GET') {
    $rows = DB::run(
        'SELECT id, ip, user_agent, last_activity, created_at, expires_at
         FROM user_sessions
         WHERE user_id = ? AND expires_at > NOW()
         ORDER BY last_activity DESC',
        [$id]
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    ErrorResponse::ok($rows);
}

if (Request::method() === 'DELETE') {
    $stmt = DB::run('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
    $closed = $stmt->rowCount();
    Audit::log($admin['id'], 'revoke_sessions', null, null, ['user_id' => $id, 'closed' => $closed]);
    ErrorResponse::ok(['closed' => $closed]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
