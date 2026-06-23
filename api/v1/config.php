<?php
/**
 * GET /api/v1/config
 * Configuración PÚBLICA (sin autenticación) que el frontend necesita antes
 * de iniciar sesión. No expone ningún secreto ni dato sensible.
 */

ErrorResponse::ok([
    'password_reset_enabled' => Settings::passwordResetEnabled(),
    'default_locale'         => Settings::defaultLocale(),
    'viewer_actions'         => Settings::viewerActions(),
    'default_theme'          => Settings::defaultTheme(),
    'show_theme_toggle'      => Settings::showThemeToggle(),
    'table_freeze'           => Settings::tableFreeze(),
    'demo_mode'              => Demo::enabled(),
    'demo_reset_minutes'     => Demo::resetMinutes(),
    'demo_login_admin'       => Demo::loginAdmin(),
    'demo_login_viewer'      => Demo::loginViewer(),
    // Enlaces externos de la parte pública (vacío = la UI los oculta).
    'links'                  => [
        'repo'   => defined('REPO_URL') ? (string) REPO_URL : '',
        'paypal' => defined('DONATE_PAYPAL_URL') ? (string) DONATE_PAYPAL_URL : '',
        'kofi'   => defined('DONATE_KOFI_URL') ? (string) DONATE_KOFI_URL : '',
    ],
]);
