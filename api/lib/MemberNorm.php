<?php
/**
 * Normalización de los ejes MIEMBRO (encuestador) y EQUIPO cuando son texto libre
 * (iniciales tecleadas a mano): la misma persona aparece como «ABC» / «abc» /
 * «A.B.C» y hoy cada grafía es un cubo distinto en TODAS las vistas de desglose
 * (Estadísticas, Control de calidad, Índice de riesgo, Muestra).
 *
 * Ajuste POR FORMULARIO (`forms.member_normalize`), tres modos:
 *   - 'raw'       → comportamiento clásico: la clave es el string crudo.
 *   - 'normalize' → (DEFAULT) la CLAVE de agrupación pliega mayúsculas, espacios y
 *                   puntuación (solo letras/dígitos unicode, en minúsculas); la
 *                   etiqueta visible es la grafía ORIGINAL más frecuente del cubo.
 *   - 'alias'     → además, una tabla por formulario (`member_aliases`) re-mapea
 *                   variantes que la normalización no une («jlvh» → «JLHV») a una
 *                   grafía canónica, que pasa a ser la clave y la etiqueta.
 *
 * Principios (ver ROADMAP/decisión jul-2026):
 *   - Solo afecta a la AGRUPACIÓN de las vistas: no muta datos, no toca RowScope
 *     ni los filtros de envíos (que siguen comparando valores crudos), y es 100 %
 *     reversible cambiando el ajuste.
 *   - El eje miembro se fusiona DENTRO de cada equipo: las vistas agrupan al
 *     encuestador bajo su equipo, así que dos «abc» de equipos distintos son
 *     personas distintas y nunca se mezclan (la clave del cubo es compuesta).
 *   - En un `select_one` los códigos ya son canónicos: normalizar es un no-op
 *     inocuo (los códigos no varían en mayúsculas/espacios entre envíos).
 */
class MemberNorm {

    public const MODES = ['raw', 'normalize', 'alias'];

    /** Ejes con alias soportados (tabla member_aliases.axis). */
    public const AXES = ['member', 'team'];

    /** Modo validado del formulario ('raw'|'normalize'|'alias'; inválido → 'normalize'). */
    public static function mode(?string $value): string {
        return in_array($value, self::MODES, true) ? $value : 'normalize';
    }

    /**
     * Clave normalizada de una grafía: minúsculas + SOLO letras y dígitos (unicode).
     * Pliega mayúsculas, espacios y puntuación de una vez («C. M. S.», «c m s» y
     * «C.M.S» comparten clave). Los acentos NO se pliegan («José» ≠ «Jose»): para
     * eso está el modo alias. Si tras el plegado no queda nada (valor de pura
     * puntuación), se cae al string crudo recortado: mejor no fusionar que meter
     * valores raros en un mismo cubo.
     */
    public static function normKey(string $value): string {
        $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '', 'UTF-8');
        return $key !== '' ? $key : trim($value);
    }

    /**
     * Mapa de alias del formulario: ['member' => [from_key => to_value], 'team' => …].
     * from_key se guarda YA normalizado (el editor lo normaliza al guardar).
     */
    public static function aliasMap(int $formId): array {
        $map = ['member' => [], 'team' => []];
        foreach (DB::run(
            'SELECT axis, from_key, to_value FROM member_aliases WHERE form_id = ?',
            [$formId]
        )->fetchAll() as $r) {
            if (isset($map[$r['axis']])) {
                $map[$r['axis']][$r['from_key']] = $r['to_value'];
            }
        }
        return $map;
    }

    /**
     * Resolutor de claves para un formulario/modo: devuelve un callable
     * `(axis, rawValue) => ['key' => claveDelCubo, 'canon' => grafíaCanónica|null]`.
     * En modo 'raw' la clave es el crudo tal cual (canon null). En 'normalize', la
     * clave plegada. En 'alias', la clave plegada pasa por la tabla: si hay alias,
     * la clave del cubo es la clave plegada del CANÓNICO y `canon` fija la etiqueta.
     */
    public static function resolver(string $mode, int $formId): callable {
        $mode  = self::mode($mode);
        $alias = $mode === 'alias' ? self::aliasMap($formId) : null;
        return function (string $axis, string $raw) use ($mode, $alias): array {
            if ($mode === 'raw') {
                return ['key' => $raw, 'canon' => null];
            }
            $key = self::normKey($raw);
            if ($alias !== null && isset($alias[$axis][$key])) {
                $canon = $alias[$axis][$key];
                return ['key' => self::normKey($canon), 'canon' => $canon];
            }
            return ['key' => $key, 'canon' => null];
        };
    }

    /**
     * Etiqueta de un cubo fusionado: el canónico del alias si lo hubo; si no, la
     * grafía ORIGINAL más frecuente entre las filas fusionadas (empate → la menor
     * alfabéticamente, determinista). $spellings = [grafía => nºDeFilas].
     */
    public static function pickLabel(array $spellings, ?string $canon = null): string {
        if ($canon !== null && $canon !== '') return $canon;
        if (!$spellings) return '';
        ksort($spellings, SORT_STRING);
        arsort($spellings);
        return (string) array_key_first($spellings);
    }
}
