<?php
/**
 * Ajustes globales de la aplicación (tabla settings, clave/valor JSON).
 */
class Settings {
    /** Estados de despliegue de Kobo que se sincronizan por defecto. */
    public const VALID_STATUSES = ['deployed', 'draft', 'archived'];
    private const DEFAULT_SYNC_STATUSES = ['deployed'];

    /** Idiomas soportados por la interfaz. */
    public const VALID_LOCALES = ['es', 'en'];
    private const FALLBACK_LOCALE = 'es';

    /**
     * Cómo se muestran preguntas y opciones en tabla y detalles:
     *   'labels' → labels legibles del formulario (por defecto)
     *   'raw'    → nombres de campo y códigos crudos
     */
    public const VALID_LABEL_MODES = ['labels', 'raw'];
    private const DEFAULT_LABEL_MODE = 'labels';

    /** ¿Está habilitado el flujo público «olvidé mi contraseña»? (desactivado por defecto) */
    private const DEFAULT_PASSWORD_RESET = false;

    /**
     * ¿Puede cualquier usuario (no solo admin) ver SU PROPIO registro de
     * actividad en «Mi actividad»? Desactivado por defecto.
     */
    private const DEFAULT_AUDIT_SELF_VIEW = false;

    /**
     * Política de contraseña para enlaces de solo lectura compartibles:
     *   'off'      → nunca se ofrece contraseña (acceso solo por token).
     *   'optional' → el admin puede ponerla o dejarla en blanco (por defecto).
     *   'required' → cada enlace debe llevar contraseña.
     */
    public const VALID_SHARE_PASSWORD_POLICIES = ['off', 'optional', 'required'];
    private const DEFAULT_SHARE_PASSWORD_POLICY = 'optional';

    /**
     * Política para exponer adjuntos en enlaces compartibles. Los adjuntos suelen
     * contener PII sensible (rostros, testimonios en audio en formularios de DDHH),
     * por eso van desactivados por defecto y, si se permiten, exigen contraseña.
     *   off              → ningún enlace puede exponer adjuntos.
     *   require_password → un enlace CON contraseña puede exponerlos.
     */
    public const VALID_SHARE_ATTACHMENTS_POLICIES = ['off', 'require_password'];
    private const DEFAULT_SHARE_ATTACHMENTS_POLICY = 'off';

    /**
     * Acortado del nombre de los campos en la interfaz (cabeceras de tabla,
     * selector de columnas, detalle…). Desactivado por defecto; al activarlo,
     * los nombres más largos que `chars` se cortan con «…» (el completo va en el
     * tooltip y la exportación nunca acorta). Límites de cordura para `chars`.
     */
    private const DEFAULT_FIELD_TRUNCATE_CHARS = 24;
    public const FIELD_TRUNCATE_MIN = 8;
    public const FIELD_TRUNCATE_MAX = 120;

    public static function get(string $key, mixed $default = null): mixed {
        $row = DB::run('SELECT `value` FROM settings WHERE `key` = ?', [$key])->fetch();
        if (!$row) return $default;
        $decoded = json_decode($row['value'], true);
        return $decoded === null && $row['value'] !== 'null' ? $row['value'] : $decoded;
    }

    public static function set(string $key, mixed $value): void {
        DB::run(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** Lista de estados a sincronizar; siempre devuelve al menos ['deployed']. */
    public static function syncStatuses(): array {
        $v = self::get('sync_deployment_statuses', self::DEFAULT_SYNC_STATUSES);
        if (!is_array($v)) $v = self::DEFAULT_SYNC_STATUSES;
        $v = array_values(array_intersect($v, self::VALID_STATUSES));
        return $v ?: self::DEFAULT_SYNC_STATUSES;
    }

    /** Idioma por defecto del sistema ('es'|'en'). */
    public static function defaultLocale(): string {
        $v = self::get('default_locale', self::FALLBACK_LOCALE);
        return in_array($v, self::VALID_LOCALES, true) ? $v : self::FALLBACK_LOCALE;
    }

    /** Modo de etiquetas en tabla y detalles ('labels'|'raw'). */
    public static function labelMode(): string {
        $v = self::get('label_mode', self::DEFAULT_LABEL_MODE);
        return in_array($v, self::VALID_LABEL_MODES, true) ? $v : self::DEFAULT_LABEL_MODE;
    }

    /** ¿Habilitado el flujo público de recuperación de contraseña? */
    public static function passwordResetEnabled(): bool {
        return (bool) self::get('password_reset_enabled', self::DEFAULT_PASSWORD_RESET);
    }

    /** ¿Puede cualquier usuario ver su propio registro de actividad? */
    public static function auditSelfViewEnabled(): bool {
        return (bool) self::get('audit_self_view_enabled', self::DEFAULT_AUDIT_SELF_VIEW);
    }

    /**
     * Ajuste de acortado de nombres de campo: { enabled: bool, chars: int }.
     * `chars` se mantiene dentro de [FIELD_TRUNCATE_MIN, FIELD_TRUNCATE_MAX].
     */
    public static function fieldTruncate(): array {
        $enabled = (bool) self::get('field_truncate_enabled', false);
        $chars   = (int) self::get('field_truncate_chars', self::DEFAULT_FIELD_TRUNCATE_CHARS);
        $chars   = max(self::FIELD_TRUNCATE_MIN, min(self::FIELD_TRUNCATE_MAX, $chars));
        return ['enabled' => $enabled, 'chars' => $chars];
    }

    /** Política de contraseña para enlaces compartibles ('off'|'optional'|'required'). */
    public static function sharePasswordPolicy(): string {
        $v = self::get('share_password_policy', self::DEFAULT_SHARE_PASSWORD_POLICY);
        return in_array($v, self::VALID_SHARE_PASSWORD_POLICIES, true) ? $v : self::DEFAULT_SHARE_PASSWORD_POLICY;
    }

    /** Política de adjuntos en enlaces compartibles ('off'|'require_password'). */
    public static function shareAttachmentsPolicy(): string {
        $v = self::get('share_attachments_policy', self::DEFAULT_SHARE_ATTACHMENTS_POLICY);
        return in_array($v, self::VALID_SHARE_ATTACHMENTS_POLICIES, true) ? $v : self::DEFAULT_SHARE_ATTACHMENTS_POLICY;
    }

    /**
     * ¿Hay un transporte de email configurado? (clave de Resend presente).
     * Es un secreto de config.php, no un ajuste de BD; útil para avisar en la UI.
     */
    public static function mailConfigured(): bool {
        return defined('RESEND_API_KEY') && RESEND_API_KEY !== '';
    }

    /**
     * Registra la última ejecución de un cron (clave `cron_runs`, objeto por nombre):
     *   { "<name>": { "at": "YYYY-MM-DD HH:MM:SS", ...info } }
     * `info` suele llevar `ok` (bool) y un pequeño resumen (conteos). Pensado para
     * llamarse al final de cada cron (CLI).
     */
    public static function recordCronRun(string $name, array $info = []): void {
        $runs = self::get('cron_runs', []);
        if (!is_array($runs)) $runs = [];
        $runs[$name] = array_merge(['at' => date('Y-m-d H:i:s')], $info);
        self::set('cron_runs', $runs);
    }

    /** Últimas ejecuciones registradas de los crons (objeto por nombre). */
    public static function cronRuns(): array {
        $runs = self::get('cron_runs', []);
        return is_array($runs) ? $runs : [];
    }

    /**
     * Tema visual por defecto del sitio ('light'|'dark'|'auto'). Solo aplica a
     * quien no haya elegido tema con el selector (la elección del usuario,
     * guardada en su navegador, siempre gana).
     */
    public const VALID_THEMES = ['light', 'dark', 'auto'];
    private const DEFAULT_THEME = 'auto';

    /** ¿Se muestra el selector de tema (portada y perfil)? Activado por defecto. */
    private const DEFAULT_SHOW_THEME_TOGGLE = true;

    /** Tema por defecto del sitio ('light'|'dark'|'auto'). */
    public static function defaultTheme(): string {
        $v = self::get('default_theme', self::DEFAULT_THEME);
        return in_array($v, self::VALID_THEMES, true) ? $v : self::DEFAULT_THEME;
    }

    /** ¿Selector de tema visible para los usuarios? */
    public static function showThemeToggle(): bool {
        return (bool) self::get('show_theme_toggle', self::DEFAULT_SHOW_THEME_TOGGLE);
    }

    public const VIEWER_ACTION_KEYS = ['enketo', 'update', 'resync', 'login'];

    /**
     * Acciones sobre formularios que un viewer puede ejecutar desde «Mis
     * formularios», si el admin las habilita (todas desactivadas por defecto).
     * Los admin las tienen siempre.
     */
    public static function viewerActions(): array {
        return [
            'enketo' => (bool) self::get('viewer_can_enketo', false), // abrir formulario público (Enketo)
            'update' => (bool) self::get('viewer_can_update', false), // sincronización incremental
            'resync' => (bool) self::get('viewer_can_resync', false), // sincronización completa
            'login'  => (bool) self::get('viewer_can_login', false),  // abrir en KoboToolbox
        ];
    }
}
