<?php
/**
 * CLI: renombrar en los filtros de columnas guardados (`user_form_permissions.field_filter`
 * y `share_links.field_filter`) las claves que quedaron obsoletas tras un cambio en la
 * normalización del esquema — hoy: las filas de preguntas score/rank, que hasta 1.27.1 se
 * registraban por su nombre hoja pelado (`carrt`) y ahora viven en su ruta completa
 * (`Q6/carrt`, la clave real del payload). Con la clave vieja el ocultado NO casaba con el
 * payload y el campo se filtraba igualmente: ejecutar esto UNA vez tras actualizar cierra
 * esa fuga en los filtros existentes.
 *
 * Uso:
 *   php api/cli/fix_field_filters.php            aplica los renombres
 *   php api/cli/fix_field_filters.php --dry-run  solo muestra lo que haría
 *
 * Solo renombra cuando el esquema regenerado tiene EXACTAMENTE una hoja con ese nombre
 * (sin ambigüedad); las claves desconocidas o ambiguas se dejan tal cual (inertes: si no
 * casan con ninguna clave del payload, no ocultan ni exponen nada). Idempotente. Requiere
 * que los esquemas ya estén re-normalizados (ocurre solo en el siguiente sync de envíos,
 * o al instante con «Sincronizar formularios» en el panel de administración).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

require getenv('KM_CONFIG') ?: __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

// Esquema por formulario: rutas válidas + índice hoja → rutas (para el renombre).
$schemas = [];
$schemaFor = function (int $formId) use (&$schemas): array {
    if (!isset($schemas[$formId])) {
        $raw   = DB::run('SELECT schema_json FROM forms WHERE id = ?', [$formId])->fetchColumn();
        $s     = $raw ? json_decode((string) $raw, true) : null;
        $paths = array_keys($s['fields'] ?? []);
        $byLeaf = [];
        foreach ($paths as $p) {
            $slash = strrpos((string) $p, '/');
            $leaf  = $slash === false ? (string) $p : substr((string) $p, $slash + 1);
            $byLeaf[$leaf][] = (string) $p;
        }
        $schemas[$formId] = ['paths' => array_flip(array_map('strval', $paths)), 'byLeaf' => $byLeaf];
    }
    return $schemas[$formId];
};

$renames = 0;
$rows    = 0;
foreach (['share_links', 'user_form_permissions'] as $table) {
    foreach (DB::run("SELECT id, form_id, field_filter FROM $table WHERE field_filter IS NOT NULL")->fetchAll() as $r) {
        $filter = json_decode((string) $r['field_filter'], true);
        if (!is_array($filter)) continue;
        $sch = $schemaFor((int) $r['form_id']);
        $changed = false;
        foreach (['hidden', 'readonly'] as $k) {
            if (empty($filter[$k]) || !is_array($filter[$k])) continue;
            foreach ($filter[$k] as $i => $key) {
                $key = (string) $key;
                if (isset($sch['paths'][$key])) continue;            // sigue siendo válida
                $cands = $sch['byLeaf'][$key] ?? [];
                if (count($cands) === 1) {                           // renombre inequívoco
                    fwrite(STDOUT, ($dryRun ? '[dry-run] ' : '') .
                        "$table #{$r['id']} (form {$r['form_id']}): $k «{$key}» → «{$cands[0]}»\n");
                    $filter[$k][$i] = $cands[0];
                    $changed = true;
                    $renames++;
                }
            }
            $filter[$k] = array_values(array_unique($filter[$k]));
        }
        if ($changed && !$dryRun) {
            DB::run("UPDATE $table SET field_filter = ? WHERE id = ?",
                [json_encode($filter, JSON_UNESCAPED_UNICODE), $r['id']]);
            $rows++;
        }
    }
}

fwrite(STDOUT, $dryRun
    ? "[dry-run] $renames renombre(s) pendientes.\n"
    : ($renames ? "✓ $renames renombre(s) aplicados en $rows fila(s).\n" : "✓ Nada que renombrar: los filtros están al día.\n"));
