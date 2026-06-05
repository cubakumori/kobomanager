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
}
