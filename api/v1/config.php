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
    'table_header_lines'     => Settings::tableHeaderLines(),
    'pct_format'             => Settings::pctFormat(),
    'qc_admit_batch'         => Settings::qcAdmitBatch(),
    'show_view_submissions_link' => Settings::showViewSubmissionsLink(),
    // Muestras: visibilidad del «reparto rápido» del editor y paleta de cumplimiento
    // del panel (ajustes de presentación; nada sensible).
    'sample_show_quick_fill' => Settings::sampleShowQuickFill(),
    'sample_palette'         => Settings::samplePalette(),
    'sample_mono_color'      => Settings::sampleMonoColor(),
    'sync_on_login'          => Settings::syncOnLogin(),
    // Web Push: la clave pública VAPID no es secreta (es la applicationServerKey
    // que el navegador necesita para suscribirse). Vacía = push no configurado.
    'push_public_key'        => WebPush::configured() ? VAPID_PUBLIC_KEY : '',
    'demo_mode'              => Demo::enabled(),
    'demo_reset_minutes'     => Demo::resetMinutes(),
    'demo_login_admin'       => Demo::loginAdmin(),
    'demo_login_viewer'      => Demo::loginViewer(),
    // Visibilidad de la parte pública de escaparate.
    'support_page_enabled'   => Settings::supportPageEnabled(),
    'landing_cta_enabled'    => Settings::landingCtaEnabled(),
    // Enlaces externos de la parte pública (vacío = la UI los oculta).
    'links'                  => [
        'repo'   => defined('REPO_URL') ? (string) REPO_URL : '',
        'paypal' => defined('DONATE_PAYPAL_URL') ? (string) DONATE_PAYPAL_URL : '',
        'kofi'   => defined('DONATE_KOFI_URL') ? (string) DONATE_KOFI_URL : '',
    ],
]);
