<?php
/**
 * Utilidades de geolocalización para envíos de Kobo.
 *
 * Kobo guarda:
 *   - geopoint: "lat lng alt acc"  (una coordenada)
 *   - geotrace: "lat lng alt acc;lat lng alt acc;…"  (línea)
 *   - geoshape: igual que geotrace pero polígono cerrado
 *   - _geolocation: [lat, lng] derivado del geopoint principal (puede ser [null,null])
 */
class Geo {

    private const GEO_TYPES = ['geopoint', 'geoshape', 'geotrace'];

    /** Parsea "lat lng [alt] [acc]" a [lat, lng] válido, o null. */
    public static function parsePoint(?string $s): ?array {
        if (!is_string($s)) return null;
        $parts = preg_split('/\s+/', trim($s));
        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) return null;
        $lat = (float) $parts[0];
        $lng = (float) $parts[1];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
        if ($lat === 0.0 && $lng === 0.0) return null; // descartar (0,0) sin señal
        return [$lat, $lng];
    }

    /** Parsea "p1;p2;…" (geotrace/geoshape) a lista de [lat,lng]. */
    public static function parsePath(?string $s): array {
        if (!is_string($s)) return [];
        $pts = [];
        foreach (explode(';', $s) as $chunk) {
            $p = self::parsePoint($chunk);
            if ($p) $pts[] = $p;
        }
        return $pts;
    }

    /**
     * Features geográficas de un envío, listas para el mapa. Cada una:
     *   { field, label, kind: 'point'|'line'|'polygon', points: [[lat,lng],…] }
     * $labels mapea clave→etiqueta (de FormSchema::resolve) para nombrar la feature.
     */
    public static function features(array $payload, ?array $schema, array $labels = []): array {
        $features = [];
        $hasPoint = false;

        foreach (($schema['fields'] ?? []) as $path => $fd) {
            $type = $fd['type'] ?? '';
            if (!in_array($type, self::GEO_TYPES, true)) continue;
            $v = $payload[$path] ?? null;
            if (!is_string($v) || trim($v) === '') continue;

            $label = $labels[$path] ?? ($labels[$fd['leaf'] ?? $path] ?? $path);
            if ($type === 'geopoint') {
                $pt = self::parsePoint($v);
                if ($pt) { $features[] = ['field' => $path, 'label' => $label, 'kind' => 'point', 'points' => [$pt]]; $hasPoint = true; }
            } else {
                $pts = self::parsePath($v);
                if (count($pts) >= 2) {
                    $features[] = [
                        'field'  => $path,
                        'label'  => $label,
                        'kind'   => $type === 'geoshape' ? 'polygon' : 'line',
                        'points' => $pts,
                    ];
                }
            }
        }

        // Respaldo: punto principal derivado por Kobo si no hubo geopoint explícito.
        if (!$hasPoint) {
            $gl = $payload['_geolocation'] ?? null;
            if (is_array($gl) && isset($gl[0], $gl[1]) && is_numeric($gl[0]) && is_numeric($gl[1])) {
                $features[] = ['field' => '_geolocation', 'label' => null, 'kind' => 'point', 'points' => [[(float) $gl[0], (float) $gl[1]]]];
            }
        }

        return $features;
    }

    /** Punto principal [lat,lng] de un envío (primer geopoint o _geolocation), o null. */
    public static function primaryPoint(array $payload, ?array $schema): ?array {
        foreach (($schema['fields'] ?? []) as $path => $fd) {
            if (($fd['type'] ?? '') === 'geopoint') {
                $pt = self::parsePoint($payload[$path] ?? null);
                if ($pt) return $pt;
            }
        }
        $gl = $payload['_geolocation'] ?? null;
        if (is_array($gl) && isset($gl[0], $gl[1]) && is_numeric($gl[0]) && is_numeric($gl[1])) {
            return [(float) $gl[0], (float) $gl[1]];
        }
        return null;
    }
}
