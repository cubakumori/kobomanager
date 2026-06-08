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
        // Estado inicial automático global ('' = sin auto-estado) + catálogo para el selector.
        'initial_review_status'      => Settings::initialReviewStatus() ?? '',
        'review_statuses'            => ReviewStatus::active(),
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

    if (array_key_exists('initial_review_status', $body)) {
        $key = trim((string) $body['initial_review_status']);
        // '' o 'pending' = sin auto-estado; cualquier otra debe ser un estado válido.
        if ($key !== '' && $key !== 'pending' && !ReviewStatus::isAssignable($key)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Estado inicial de revisión no válido');
        }
        Settings::set('initial_review_status', $key === 'pending' ? '' : $key);
        $out['initial_review_status'] = $key === 'pending' ? '' : $key;
    }

    Audit::log($admin['id'], 'update_settings', null, null, $out);
    ErrorResponse::ok($out);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
