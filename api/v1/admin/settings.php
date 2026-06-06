<?php
/**
 * /api/v1/admin/settings   (solo admin)
 *   GET → ajustes globales actuales.
 *   PUT → { sync_deployment_statuses: ["deployed","draft","archived"] }
 */

$admin = Auth::requireAdmin();

if (Request::method() === 'GET') {
    ErrorResponse::ok([
        'sync_deployment_statuses' => Settings::syncStatuses(),
        'valid_statuses'           => Settings::VALID_STATUSES,
        'default_locale'           => Settings::defaultLocale(),
        'valid_locales'            => Settings::VALID_LOCALES,
        'label_mode'               => Settings::labelMode(),
        'valid_label_modes'        => Settings::VALID_LABEL_MODES,
        'password_reset_enabled'   => Settings::passwordResetEnabled(),
        'mail_configured'          => Settings::mailConfigured(),
    ]);
}

if (Request::method() === 'PUT') {
    $body = Request::json();
    $out  = [];

    if (array_key_exists('sync_deployment_statuses', $body)) {
        $in = $body['sync_deployment_statuses'];
        if (!is_array($in)) {
            ErrorResponse::send('VALIDATION_ERROR', 'sync_deployment_statuses debe ser una lista');
        }
        $clean = array_values(array_intersect(
            array_map('strtolower', array_map('strval', $in)),
            Settings::VALID_STATUSES
        ));
        if (!$clean) {
            ErrorResponse::send('VALIDATION_ERROR', 'Debe seleccionarse al menos un estado válido');
        }
        Settings::set('sync_deployment_statuses', $clean);
        $out['sync_deployment_statuses'] = $clean;
    }

    if (array_key_exists('default_locale', $body)) {
        $loc = (string) $body['default_locale'];
        if (!in_array($loc, Settings::VALID_LOCALES, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Idioma no válido');
        }
        Settings::set('default_locale', $loc);
        $out['default_locale'] = $loc;
    }

    if (array_key_exists('label_mode', $body)) {
        $mode = (string) $body['label_mode'];
        if (!in_array($mode, Settings::VALID_LABEL_MODES, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Modo de etiquetas no válido');
        }
        Settings::set('label_mode', $mode);
        $out['label_mode'] = $mode;
    }

    if (array_key_exists('password_reset_enabled', $body)) {
        $enabled = (bool) $body['password_reset_enabled'];
        Settings::set('password_reset_enabled', $enabled);
        $out['password_reset_enabled'] = $enabled;
    }

    Audit::log($admin['id'], 'update_settings', null, null, $out);
    ErrorResponse::ok($out);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
