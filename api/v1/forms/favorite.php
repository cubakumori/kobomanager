<?php
/**
 * PUT /api/v1/forms/{id}/favorite   (requiere can_view)
 *
 * Body: { favorite: bool }
 *
 * Marca/desmarca el formulario como FAVORITO del usuario autenticado (estrella
 * de «Mis formularios»). Preferencia pura: no toca permisos ni datos del
 * formulario. Idempotente (marcar dos veces = una).
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'PUT') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$favorite = !empty(Request::json()['favorite']);

if ($favorite) {
    DB::run(
        'INSERT IGNORE INTO user_form_favorites (user_id, form_id) VALUES (?, ?)',
        [$user['id'], $formId]
    );
} else {
    DB::run(
        'DELETE FROM user_form_favorites WHERE user_id = ? AND form_id = ?',
        [$user['id'], $formId]
    );
}

ErrorResponse::ok(['favorite' => $favorite]);
