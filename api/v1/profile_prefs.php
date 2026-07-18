<?php
/**
 * PUT /api/v1/profile/prefs   (usuario autenticado, sus propias preferencias de UI)
 *
 * Body: { forms_view: { account: <id|null>, type: 'deployed'|'draft'|'archived'|'', favorites: bool } }
 *
 * Guarda preferencias de INTERFAZ por usuario en users.ui_prefs (JSON). Lista
 * blanca de claves de primer nivel: solo se aceptan las conocidas y cada una se
 * valida a su forma canónica (nunca se persiste JSON arbitrario del cliente).
 * Las claves ausentes del body se conservan; enviar null borra esa clave.
 * El objeto completo viaja al frontend en /auth/me y en el login.
 */

$user = Auth::require();

if (Request::method() !== 'PUT') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$body = Request::json();

$prefs = $user['ui_prefs'] ?? [];
if (!is_array($prefs)) $prefs = [];

// --- forms_view: filtros persistidos de «Mis formularios». ---
if (array_key_exists('forms_view', $body)) {
    $fv = $body['forms_view'];
    if ($fv === null) {
        unset($prefs['forms_view']);
    } elseif (is_array($fv)) {
        $account = $fv['account'] ?? null;
        $type    = (string) ($fv['type'] ?? '');
        if (!in_array($type, ['', 'deployed', 'draft', 'archived'], true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Tipo no válido');
        }
        $prefs['forms_view'] = [
            'account'   => is_numeric($account) ? (int) $account : null,
            'type'      => $type,
            'favorites' => !empty($fv['favorites']),
        ];
    } else {
        ErrorResponse::send('VALIDATION_ERROR', 'forms_view no válido');
    }
}

DB::run(
    'UPDATE users SET ui_prefs = ? WHERE id = ?',
    [$prefs ? json_encode($prefs, JSON_UNESCAPED_UNICODE) : null, $user['id']]
);

ErrorResponse::ok(['ui_prefs' => $prefs ?: null]);
