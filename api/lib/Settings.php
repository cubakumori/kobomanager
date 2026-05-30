<?php
/**
 * Ajustes globales de la aplicación (tabla settings, clave/valor JSON).
 */
class Settings {
    /** Estados de despliegue de Kobo que se sincronizan por defecto. */
    public const VALID_STATUSES = ['deployed', 'draft', 'archived'];
    private const DEFAULT_SYNC_STATUSES = ['deployed'];

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
}
