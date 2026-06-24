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
        'audit_self_view_enabled'  => Settings::auditSelfViewEnabled(),
        'mail_configured'          => Settings::mailConfigured(),
        'viewer_actions'           => Settings::viewerActions(),
        'share_password_policy'      => Settings::sharePasswordPolicy(),
        'valid_share_password_policies' => Settings::VALID_SHARE_PASSWORD_POLICIES,
        'share_attachments_policy'   => Settings::shareAttachmentsPolicy(),
        'valid_share_attachments_policies' => Settings::VALID_SHARE_ATTACHMENTS_POLICIES,
        'field_truncate'             => Settings::fieldTruncate(),
        'field_truncate_min'         => Settings::FIELD_TRUNCATE_MIN,
        'field_truncate_max'         => Settings::FIELD_TRUNCATE_MAX,
        'default_theme'              => Settings::defaultTheme(),
        'valid_themes'               => Settings::VALID_THEMES,
        'show_theme_toggle'          => Settings::showThemeToggle(),
        'table_freeze'               => Settings::tableFreeze(),
        'valid_table_freeze'         => Settings::VALID_TABLE_FREEZE,
        'table_header_lines'         => Settings::tableHeaderLines(),
        'valid_table_header_lines'   => Settings::VALID_TABLE_HEADER_LINES,
        'notifications_default_on'   => Settings::notificationsDefaultOn(),
        'support_page_enabled'       => Settings::supportPageEnabled(),
        'landing_cta_enabled'        => Settings::landingCtaEnabled(),
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

    if (array_key_exists('default_theme', $body)) {
        $theme = (string) $body['default_theme'];
        if (!in_array($theme, Settings::VALID_THEMES, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Tema no válido');
        }
        Settings::set('default_theme', $theme);
        $out['default_theme'] = $theme;
    }

    if (array_key_exists('show_theme_toggle', $body)) {
        Settings::set('show_theme_toggle', (bool) $body['show_theme_toggle']);
        $out['show_theme_toggle'] = (bool) $body['show_theme_toggle'];
    }

    if (array_key_exists('notifications_default_on', $body)) {
        Settings::set('notifications_default_on', (bool) $body['notifications_default_on']);
        $out['notifications_default_on'] = (bool) $body['notifications_default_on'];
    }

    if (array_key_exists('support_page_enabled', $body)) {
        Settings::set('support_page_enabled', (bool) $body['support_page_enabled']);
        $out['support_page_enabled'] = (bool) $body['support_page_enabled'];
    }

    if (array_key_exists('landing_cta_enabled', $body)) {
        Settings::set('landing_cta_enabled', (bool) $body['landing_cta_enabled']);
        $out['landing_cta_enabled'] = (bool) $body['landing_cta_enabled'];
    }

    if (array_key_exists('table_freeze', $body)) {
        $tf = (string) $body['table_freeze'];
        if (!in_array($tf, Settings::VALID_TABLE_FREEZE, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Modo de congelado no válido');
        }
        Settings::set('table_freeze', $tf);
        $out['table_freeze'] = $tf;
    }

    if (array_key_exists('table_header_lines', $body)) {
        $hl = (int) $body['table_header_lines'];
        if (!in_array($hl, Settings::VALID_TABLE_HEADER_LINES, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Número de líneas de encabezado no válido');
        }
        Settings::set('table_header_lines', $hl);
        $out['table_header_lines'] = $hl;
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

    if (array_key_exists('audit_self_view_enabled', $body)) {
        $enabled = (bool) $body['audit_self_view_enabled'];
        Settings::set('audit_self_view_enabled', $enabled);
        $out['audit_self_view_enabled'] = $enabled;
    }

    if (array_key_exists('share_password_policy', $body)) {
        $pol = (string) $body['share_password_policy'];
        if (!in_array($pol, Settings::VALID_SHARE_PASSWORD_POLICIES, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Política de contraseña de enlaces no válida');
        }
        Settings::set('share_password_policy', $pol);
        $out['share_password_policy'] = $pol;
    }

    if (array_key_exists('share_attachments_policy', $body)) {
        $pol = (string) $body['share_attachments_policy'];
        if (!in_array($pol, Settings::VALID_SHARE_ATTACHMENTS_POLICIES, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Política de adjuntos de enlaces no válida');
        }
        Settings::set('share_attachments_policy', $pol);
        $out['share_attachments_policy'] = $pol;
    }

    if (array_key_exists('field_truncate', $body) && is_array($body['field_truncate'])) {
        $ft = $body['field_truncate'];
        $enabled = (bool) ($ft['enabled'] ?? false);
        $chars   = (int) ($ft['chars'] ?? Settings::FIELD_TRUNCATE_MIN);
        $chars   = max(Settings::FIELD_TRUNCATE_MIN, min(Settings::FIELD_TRUNCATE_MAX, $chars));
        Settings::set('field_truncate_enabled', $enabled);
        Settings::set('field_truncate_chars', $chars);
        $out['field_truncate'] = ['enabled' => $enabled, 'chars' => $chars];
    }

    if (array_key_exists('viewer_actions', $body) && is_array($body['viewer_actions'])) {
        $va = [];
        foreach (Settings::VIEWER_ACTION_KEYS as $k) {
            if (array_key_exists($k, $body['viewer_actions'])) {
                $val = (bool) $body['viewer_actions'][$k];
                Settings::set('viewer_can_' . $k, $val);
                $va[$k] = $val;
            }
        }
        if ($va) $out['viewer_actions'] = $va;
    }

    Audit::log($admin['id'], 'update_settings', null, null, $out);
    ErrorResponse::ok($out);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
