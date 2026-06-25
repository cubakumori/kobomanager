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

    /**
     * Congelado de columnas en las tablas de la interfaz al hacer scroll lateral:
     *   'first' → la primera columna queda fija (por defecto)
     *   'none'  → ninguna columna fija
     */
    public const VALID_TABLE_FREEZE = ['first', 'none'];
    private const DEFAULT_TABLE_FREEZE = 'first';

    /** Modo de congelado de columnas en tablas ('first'|'none'). */
    public static function tableFreeze(): string {
        $v = self::get('table_freeze', self::DEFAULT_TABLE_FREEZE);
        return in_array($v, self::VALID_TABLE_FREEZE, true) ? $v : self::DEFAULT_TABLE_FREEZE;
    }

    /**
     * Nº máximo de líneas a las que se ajusta el encabezado de las columnas en
     * las tablas de envíos (1 = una sola línea, como antes; 2|3 = el encabezado
     * envuelve hasta N líneas con un ancho acotado, recortando con «…» si sobra).
     */
    public const VALID_TABLE_HEADER_LINES = [1, 2, 3];
    private const DEFAULT_TABLE_HEADER_LINES = 2;

    public static function tableHeaderLines(): int {
        $v = (int) self::get('table_header_lines', self::DEFAULT_TABLE_HEADER_LINES);
        return in_array($v, self::VALID_TABLE_HEADER_LINES, true) ? $v : self::DEFAULT_TABLE_HEADER_LINES;
    }

    /**
     * ¿Los usuarios reciben el resumen diario por DEFECTO en los formularios activos
     * que pueden ver? Cuando está activo, un formulario sin preferencia explícita en
     * `notification_config` se considera suscrito (el usuario puede desmarcarlo, lo que
     * guarda una preferencia explícita en 0). Desactivado por defecto.
     */
    public static function notificationsDefaultOn(): bool {
        return (bool) self::get('notifications_default_on', false);
    }

    /**
     * Visibilidad de la parte pública «de escaparate». Ambos activados por defecto.
     *   - support_page_enabled → la página «Apoyar» (/apoyar) y sus enlaces.
     *   - landing_cta_enabled  → la banda de cierre de la portada («monta tu instancia»).
     * Un operador que autoaloja para uso interno puede ocultarlas sin tocar código.
     */
    private const DEFAULT_SUPPORT_PAGE = true;
    private const DEFAULT_LANDING_CTA  = true;

    /** ¿Se muestra la página «Apoyar» y sus enlaces? */
    public static function supportPageEnabled(): bool {
        return (bool) self::get('support_page_enabled', self::DEFAULT_SUPPORT_PAGE);
    }

    /** ¿Se muestra la CTA de cierre de la portada? */
    public static function landingCtaEnabled(): bool {
        return (bool) self::get('landing_cta_enabled', self::DEFAULT_LANDING_CTA);
    }

    /**
     * Orden de las tarjetas en «Mis formularios» (global, configurable por el admin):
     *   'account_name'   → cuenta + nombre (por defecto, alfabético).
     *   'name'           → nombre del formulario (A→Z).
     *   'recent_sync'    → últimos envíos sincronizados primero (sin sincronizar al final).
     *   'recent_created' → añadidos más recientemente primero.
     * El SQL de cada opción vive en v1/forms/index.php (lista blanca, nunca interpolado).
     */
    public const VALID_FORMS_ORDER = ['account_name', 'name', 'recent_sync', 'recent_created'];
    private const DEFAULT_FORMS_ORDER = 'account_name';

    /** Criterio de orden de «Mis formularios». */
    public static function formsOrder(): string {
        $v = self::get('forms_order', self::DEFAULT_FORMS_ORDER);
        return in_array($v, self::VALID_FORMS_ORDER, true) ? $v : self::DEFAULT_FORMS_ORDER;
    }

    /**
     * Alcance por defecto de la página de estadísticas (forms/{id}/stats): qué
     * subconjunto de envíos se muestra al abrirla, antes de que el usuario pulse
     * otra tarjeta del encabezado.
     *   'all'      → todos los envíos.
     *   'approved' → solo los aprobados (por defecto: lo «comúnmente interesante»).
     * El usuario siempre puede cambiar a cualquiera de los cinco estados en la
     * propia página; esto solo decide cuál se carga primero.
     */
    public const VALID_STATS_DEFAULT_SCOPE = ['all', 'approved'];
    private const DEFAULT_STATS_DEFAULT_SCOPE = 'approved';

    /** Alcance por defecto de la página de estadísticas ('all'|'approved'). */
    public static function statsDefaultScope(): string {
        $v = self::get('stats_default_scope', self::DEFAULT_STATS_DEFAULT_SCOPE);
        return in_array($v, self::VALID_STATS_DEFAULT_SCOPE, true) ? $v : self::DEFAULT_STATS_DEFAULT_SCOPE;
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
