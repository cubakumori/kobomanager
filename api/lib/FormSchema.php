<?php
/**
 * Esquema legible de un formulario (etiquetas de preguntas y de opciones).
 *
 * Descarga el contenido XLSForm del asset en Kobo (`content.survey` / `content.choices`),
 * lo normaliza a una estructura compacta multi-idioma y la cachea en `forms.schema_json`.
 * Después, `resolve()` aplana esa estructura al idioma del usuario para que el frontend
 * muestre «Satisfacción» en vez de `satisfaccion` y «Muy alta» en vez de `1`.
 *
 * Las claves de los envíos de Kobo llevan la ruta del grupo (`g_authors/g_person/prov`),
 * así que el esquema indexa cada campo tanto por su ruta completa como por su nombre hoja.
 */
class FormSchema {

    /** Tipos del survey que no son campos de datos con etiqueta propia. */
    private const SKIP_TYPES = ['note', 'start', 'end', 'today', 'deviceid', 'audit',
        'phonenumber', 'username', 'simserial', 'subscriberid', 'calculate'];

    /**
     * Descarga el contenido del asset y guarda el esquema normalizado en `forms`.
     * No lanza: si el contenido no se puede leer, deja el esquema como está.
     */
    public static function fetchAndStore(int $formId, string $assetUid, KoboClient $client): void {
        try {
            $content = $client->getAssetContent($assetUid);
            if (!$content) return;
            $schema = self::normalize($content);
            DB::run(
                'UPDATE forms SET schema_json = ?, schema_synced_at = NOW() WHERE id = ?',
                [json_encode($schema, JSON_UNESCAPED_UNICODE), $formId]
            );
        } catch (Throwable $e) {
            // El esquema es un extra para mostrar etiquetas: si falla, no debe
            // interrumpir la sincronización de formularios ni de envíos.
            error_log('FormSchema::fetchAndStore form ' . $formId . ': ' . $e->getMessage());
        }
    }

    /**
     * Convierte el `content` de Kobo en:
     *   languages: lista de traducciones (puede contener null en formularios mono-idioma)
     *   fields:    ruta_completa => { leaf, list, multi, label: { idioma: texto } }
     *   choices:   list_name => { valor: { idioma: texto } }
     */
    public static function normalize(array $content): array {
        $translations = $content['translations'] ?? [null];
        $survey       = $content['survey'] ?? [];
        $choices      = $content['choices'] ?? [];

        $fields = [];
        $stack  = []; // pila de nombres de grupo/repeat para reconstruir la ruta
        foreach ($survey as $row) {
            $type = (string) ($row['type'] ?? '');
            $name = $row['name'] ?? null;

            if ($type === 'begin_group' || $type === 'begin_repeat') {
                if ($name !== null && $name !== '') $stack[] = $name;
                continue;
            }
            if ($type === 'end_group' || $type === 'end_repeat') {
                array_pop($stack);
                continue;
            }
            if ($name === null || $name === '' || in_array($type, self::SKIP_TYPES, true)) {
                continue;
            }

            // Lista de opciones: campo dedicado (Kobo normaliza a select_from_list_name)
            // o, como respaldo, el segundo token del tipo ("select_one lista").
            $list = $row['select_from_list_name'] ?? null;
            if ($list === null && preg_match('/^select_(?:one|multiple)\s+(\S+)/', $type, $m)) {
                $list = $m[1];
            }

            $full = $stack ? implode('/', $stack) . '/' . $name : $name;
            $fields[$full] = [
                'leaf'  => $name,
                'type'  => $type,
                'list'  => $list,
                'multi' => str_starts_with($type, 'select_multiple'),
                'label' => self::labelMap($row['label'] ?? null, $translations),
            ];
        }

        $choiceMap = [];
        foreach ($choices as $c) {
            $list = $c['list_name'] ?? null;
            $val  = $c['name'] ?? null;
            if ($list === null || $val === null) continue;
            $choiceMap[$list][(string) $val] = self::labelMap($c['label'] ?? null, $translations);
        }

        return [
            'languages'        => array_values($translations),
            'default_language' => $content['settings']['default_language'] ?? null,
            'fields'           => $fields,
            'choices'          => $choiceMap,
        ];
    }

    /**
     * Aplana el esquema cacheado al idioma de UI dado. Devuelve:
     *   labels:  clave (ruta y hoja) => etiqueta de la pregunta
     *   options: clave (ruta y hoja) => { valor => etiqueta de la opción }
     *   multi:   lista de claves que son select_multiple (valores separados por espacio)
     */
    public static function resolve(?array $schema, string $locale): array {
        if (!$schema || empty($schema['fields'])) {
            return ['labels' => [], 'options' => [], 'multi' => []];
        }
        $langKey = self::pickLanguage($schema, $locale);
        $choices = $schema['choices'] ?? [];

        $labels = [];
        $options = [];
        $multi = [];
        foreach ($schema['fields'] as $full => $f) {
            $leaf = $f['leaf'] ?? $full;

            $txt = self::pickText($f['label'] ?? [], $langKey);
            if ($txt !== null && $txt !== '') {
                $labels[$full] = $txt;
                if (!isset($labels[$leaf])) $labels[$leaf] = $txt;
            }

            $list = $f['list'] ?? null;
            if ($list !== null && isset($choices[$list])) {
                $opts = [];
                foreach ($choices[$list] as $val => $lblMap) {
                    $t = self::pickText($lblMap, $langKey);
                    if ($t !== null && $t !== '') $opts[(string) $val] = $t;
                }
                if ($opts) {
                    $options[$full] = $opts;
                    if (!isset($options[$leaf])) $options[$leaf] = $opts;
                }
            }

            if (!empty($f['multi'])) {
                $multi[$full] = true;
                $multi[$leaf] = true;
            }
        }

        return ['labels' => $labels, 'options' => $options, 'multi' => array_keys($multi)];
    }

    // ---------- internos ----------

    /**
     * Normaliza el campo `label` (string en mono-idioma, o array alineado con
     * `translations`) a { idioma: texto }. La traducción null (formularios sin
     * idiomas con nombre) se indexa con la clave vacía ''.
     */
    private static function labelMap($label, array $translations): array {
        if (is_string($label)) return ['' => $label];
        if (is_array($label)) {
            $out = [];
            foreach ($label as $i => $txt) {
                if ($txt === null || $txt === '') continue;
                $lang = $translations[$i] ?? null;
                $out[$lang === null ? '' : $lang] = $txt;
            }
            return $out;
        }
        return [];
    }

    /** Elige la traducción del formulario que mejor encaja con el locale de UI ('es'|'en'). */
    private static function pickLanguage(array $schema, string $locale): string {
        $langs = $schema['languages'] ?? [];
        $named = array_values(array_filter($langs, fn($l) => $l !== null && $l !== ''));
        if (!$named) return ''; // mono-idioma: las labels viven bajo ''

        // Coincidencia por código entre paréntesis, p. ej. "Español (es)".
        foreach ($named as $l) {
            if (preg_match('/\(' . preg_quote($locale, '/') . '\)/i', (string) $l)) return $l;
        }
        $def = $schema['default_language'] ?? null;
        if ($def !== null && in_array($def, $named, true)) return $def;
        return $named[0];
    }

    /** Texto de un label-map para una traducción concreta, con respaldos sensatos. */
    private static function pickText(array $lblMap, string $langKey): ?string {
        if ($lblMap === []) return null;
        if (array_key_exists($langKey, $lblMap)) return $lblMap[$langKey];
        if (array_key_exists('', $lblMap)) return $lblMap['']; // mono-idioma
        return reset($lblMap) ?: null;
    }
}
