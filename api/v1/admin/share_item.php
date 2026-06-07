<?php
/**
 * /api/v1/admin/shares/{id}   (solo admin)
 *
 *   DELETE            → revoca el enlace (revoked_at = NOW(); deja de funcionar
 *                       al instante pero conserva la fila y sus estadísticas).
 *   DELETE ?purge=1   → elimina la fila por completo.
 */

$admin = Auth::requireAdmin();
$id    = (int) Request::param('id');

if (Request::method() !== 'DELETE') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$link = DB::run('SELECT id, form_id FROM share_links WHERE id = ?', [$id])->fetch();
if (!$link) {
    ErrorResponse::send('NOT_FOUND', 'Enlace no encontrado');
}

$purge = !empty($_GET['purge']);
if ($purge) {
    DB::run('DELETE FROM share_links WHERE id = ?', [$id]);
} else {
    DB::run('UPDATE share_links SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$id]);
}

Audit::log($admin['id'], $purge ? 'share_delete' : 'share_revoke', (int) $link['form_id'], null, ['share_id' => $id]);
ErrorResponse::ok(['revoked' => !$purge, 'deleted' => $purge]);
