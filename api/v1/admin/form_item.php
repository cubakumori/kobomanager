<?php
/**
 * DELETE /api/v1/admin/forms/{id}   (solo admin)
 * Elimina un formulario de KoboManager y su caché de envíos asociada
 * (no toca KoboToolbox). Útil para quitar formularios que ya no se desean
 * mantener localmente (p. ej. archivados/borradores tras endurecer el filtro).
 *
 * Nota: si el formulario sigue cumpliendo el filtro de sincronización, una nueva
 * sincronización de la cuenta volverá a traerlo.
 */

$admin  = Auth::requireAdmin();
$formId = (int) Request::param('id');

if (Request::method() !== 'DELETE') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name FROM forms WHERE id = ?', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

// El borrado hace cascade sobre submissions_cache, user_form_permissions y notification_config.
DB::run('DELETE FROM forms WHERE id = ?', [$formId]);

Audit::log($admin['id'], 'delete_form', $formId, null, ['name' => $form['name']]);
ErrorResponse::ok(['deleted' => true]);
