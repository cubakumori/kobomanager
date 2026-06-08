<?php
/**
 * /api/v1/admin/forms/{id}   (solo admin)
 *   PUT    → ajustes por formulario. Hoy: { initial_review_status }
 *            ('' o 'inherit' = hereda el ajuste global; un status_key válido = override).
 *   DELETE → elimina el formulario y su caché de envíos (no toca KoboToolbox).
 *
 * Nota: si el formulario sigue cumpliendo el filtro de sincronización, una nueva
 * sincronización de la cuenta volverá a traerlo.
 */

$admin  = Auth::requireAdmin();
$formId = (int) Request::param('id');

$form = DB::run('SELECT id, name, initial_review_status FROM forms WHERE id = ?', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

if (Request::method() === 'PUT') {
    $body = Request::json();
    $out  = ['id' => $formId];

    if (array_key_exists('initial_review_status', $body)) {
        $raw = trim((string) $body['initial_review_status']);
        // '' / 'inherit' → NULL (hereda el global). 'pending' o clave válida → override.
        if ($raw === '' || $raw === 'inherit') {
            $val = null;
        } elseif ($raw === 'pending' || ReviewStatus::isAssignable($raw)) {
            $val = $raw;
        } else {
            ErrorResponse::send('VALIDATION_ERROR', 'Estado inicial de revisión no válido');
            return;
        }
        DB::run('UPDATE forms SET initial_review_status = ? WHERE id = ?', [$val, $formId]);
        $out['initial_review_status'] = $val ?? '';
        Audit::log($admin['id'], 'update_form', $formId, null, ['initial_review_status' => $val]);
    }

    ErrorResponse::ok($out);
}

if (Request::method() !== 'DELETE') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

// El borrado hace cascade sobre submissions_cache, user_form_permissions y notification_config.
DB::run('DELETE FROM forms WHERE id = ?', [$formId]);

Audit::log($admin['id'], 'delete_form', $formId, null, ['name' => $form['name']]);
ErrorResponse::ok(['deleted' => true]);
