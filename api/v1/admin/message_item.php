<?php
/**
 * /api/v1/admin/messages/{id}   (solo admin)
 *
 *   PUT    {status: new|read|archived} → cambia el estado del mensaje en la
 *          bandeja (marcar leído al abrirlo, archivar/desarchivar).
 *   DELETE → elimina el mensaje definitivamente (spam/pruebas).
 *
 * Auditoría: se registran archivar y eliminar; el paso new→read (automático al
 * abrir el mensaje) no se audita para no llenar el registro de ruido.
 */

$admin = Auth::requireAdmin();
$id    = (int) Request::param('id');

$msg = DB::run('SELECT id, status FROM contact_messages WHERE id = ?', [$id])->fetch();
if (!$msg) {
    ErrorResponse::send('NOT_FOUND', 'Mensaje no encontrado');
}

switch (Request::method()) {
    case 'PUT':
        $in     = Request::required(['status']);
        $status = $in['status'];
        if (!in_array($status, ['new', 'read', 'archived'], true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Estado no válido');
        }
        DB::run('UPDATE contact_messages SET status = ? WHERE id = ?', [$status, $id]);
        if ($status === 'archived' && $msg['status'] !== 'archived') {
            Audit::log($admin['id'], 'contact_message_archive', null, null, ['message_id' => $id]);
        }
        ErrorResponse::ok(['id' => $id, 'status' => $status]);
        break;

    case 'DELETE':
        DB::run('DELETE FROM contact_messages WHERE id = ?', [$id]);
        Audit::log($admin['id'], 'contact_message_delete', null, null, ['message_id' => $id]);
        ErrorResponse::ok(['deleted' => true]);
        break;

    default:
        ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}
