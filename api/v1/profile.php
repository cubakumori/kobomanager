<?php
/**
 * /api/v1/profile   (usuario autenticado, su propia cuenta)
 *   GET → datos del perfil + idioma (preferido y efectivo).
 *   PUT → { locale: 'es'|'en'|null }   null = seguir el idioma por defecto del sistema.
 */

$user = Auth::require();

if (Request::method() === 'GET') {
    ErrorResponse::ok([
        'id'              => $user['id'],
        'name'            => $user['name'],
        'email'           => $user['email'],
        'role'            => $user['role'],
        'locale_pref'     => $user['locale_pref'] ?? null,
        'locale'          => $user['locale'],
        'default_locale'  => Settings::defaultLocale(),
        'valid_locales'   => Settings::VALID_LOCALES,
    ]);
}

if (Request::method() === 'PUT') {
    $body = Request::json();
    if (!array_key_exists('locale', $body)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Falta locale');
    }
    $loc = $body['locale'];
    // null o '' → vuelve al idioma por defecto del sistema.
    if ($loc === null || $loc === '') {
        DB::run('UPDATE users SET locale = NULL WHERE id = ?', [$user['id']]);
        ErrorResponse::ok(['locale_pref' => null, 'locale' => Settings::defaultLocale()]);
    }
    if (!in_array($loc, Settings::VALID_LOCALES, true)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Idioma no válido');
    }
    DB::run('UPDATE users SET locale = ? WHERE id = ?', [$loc, $user['id']]);
    ErrorResponse::ok(['locale_pref' => $loc, 'locale' => $loc]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
