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
    'demo_login_hint'        => Demo::loginHint(),
]);
