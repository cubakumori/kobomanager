<?php

/**
 * Catálogo GLOBAL de estados de revisión (tabla review_statuses) + resolución del
 * estado inicial automático por formulario.
 *
 * El estado de revisión de un envío es un `status_key` (VARCHAR) que referencia una
 * fila del catálogo. Hay 4 built-ins sembrados (pending/on_hold/approved/rejected) y
 * el usuario puede añadir estados propios, relabelar/recolorear/reordenar y (salvo
 * `pending`) desactivar los suyos y los built-in no-pending.
 *
 * `is_open` = 1 ⇒ el estado sigue requiriendo acción (cuenta como NO resuelto, igual
 * que pending/on_hold); = 0 ⇒ resuelto/final (approved/rejected). Lo usan las stats.
 *
 * Esta clase NO escribe a Kobo (la revisión es 100% interna).
 */
final class ReviewStatus
{
    /** Built-ins: no se pueden borrar ni cambiar su status_key. */
    public const BUILTINS = ['pending', 'on_hold', 'approved', 'rejected'];

    /** Paleta de colores permitida (debe coincidir con el frontend). */
    public const COLORS = [
        'slate', 'gray', 'red', 'orange', 'amber', 'yellow', 'green', 'emerald',
        'teal', 'sky', 'blue', 'indigo', 'violet', 'purple', 'pink', 'rose',
    ];

    private static ?array $cache = null;

    /** Invalida la caché en memoria (tras un alta/edición/baja en el catálogo). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Catálogo completo (incluye inactivos), ordenado. Cada fila:
     * {key, label, color, is_open, is_builtin, active, sort_order}.
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = DB::run(
            'SELECT status_key, label, color, is_open, is_builtin, active, sort_order
             FROM review_statuses ORDER BY sort_order, id'
        )->fetchAll();

        self::$cache = array_map(static fn(array $r): array => [
            'key'        => $r['status_key'],
            'label'      => $r['label'] !== null && $r['label'] !== '' ? $r['label'] : null,
            'color'      => $r['color'],
            'is_open'    => (bool) $r['is_open'],
            'is_builtin' => (bool) $r['is_builtin'],
            'active'     => (bool) $r['active'],
            'sort_order' => (int) $r['sort_order'],
        ], $rows);

        return self::$cache;
    }

    /** Solo los estados activos (los que se ofrecen para revisar/filtrar). */
    public static function active(): array
    {
        return array_values(array_filter(self::all(), static fn(array $s): bool => $s['active']));
    }

    /** Claves de TODOS los estados (activos o no) — para validar filtros existentes. */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /** Claves de los estados ABIERTOS (is_open) — para el cómputo de stats. */
    public static function openKeys(): array
    {
        return array_column(array_filter(self::all(), static fn(array $s): bool => $s['is_open']), 'key');
    }

    /** ¿Es una clave de estado válida para FILTRAR (existe en el catálogo)? */
    public static function isValidFilter(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * ¿Es una clave válida para ASIGNAR en una revisión (existe y está activa)?
     * Se permite siempre `pending` (equivale a «sin revisar / reabrir»).
     */
    public static function isAssignable(string $key): bool
    {
        if ($key === 'pending') {
            return true;
        }
        foreach (self::all() as $s) {
            if ($s['key'] === $key && $s['active']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Estado inicial automático EFECTIVO de un formulario: el override del formulario
     * si lo tiene, si no el ajuste global. Devuelve null cuando no hay auto-estado
     * (none/vacío/'pending' o una clave que ya no es válida/activa → no se crea fila).
     */
    public static function initialFor(int $formId): ?string
    {
        $override = DB::run('SELECT initial_review_status FROM forms WHERE id = ?', [$formId])
            ->fetch()['initial_review_status'] ?? null;

        $key = ($override !== null && $override !== '') ? $override : Settings::initialReviewStatus();

        if ($key === null || $key === '' || $key === 'pending' || !self::isAssignable($key)) {
            return null;
        }
        return $key;
    }
}
