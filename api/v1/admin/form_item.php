<?php
/**
 * /api/v1/admin/forms/{id}   (admin, o permiso «Ajustes» sobre el formulario)
 *
 *   GET    → datos editables del formulario: la config del desglose de
 *            estadísticas por equipo → encuestador y los umbrales del control
 *            de calidad.
 *   PATCH  { stats_team_field?, stats_enumerator_field?, qc_min_duration?,
 *            qc_max_duration?, qc_min_gap?, qc_dup_min_answers?, risk_min_n? } →
 *            guarda esa config. Clave AUSENTE = no tocar; presente (aunque sea null) =
 *            fijar. Los campos de equipo son rutas del esquema o null
 *            (`stats_enumerator_field` null = usar `_submitted_by`). Los umbrales
 *            son minutos (entero ≥ 1) o null = comprobación desactivada.
 *            `qc_dup_min_answers` es nº de respuestas (1–50) o null = señal de
 *            duplicados desactivada. `risk_min_n` es el N mínimo del índice de riesgo
 *            (1–100000) o null = índice desactivado (opt-in).
 *   DELETE → SOLO admin: elimina el formulario de KoboManager y su caché (no toca
 *            Kobo). Si sigue cumpliendo el filtro de sincronización, una nueva
 *            sincronización de la cuenta volverá a traerlo.
 *
 * GET/PATCH admiten además a un usuario con `can_settings` sobre ESTE formulario
 * (permiso «Ajustes» de /admin/permissions); el borrado no se delega.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');
$method = Request::method();

if ($method === 'DELETE') {
    if ($user['role'] !== 'admin') {
        ErrorResponse::send('AUTH_INSUFFICIENT_PERMISSIONS');
    }
} else {
    Auth::requireForm($user, $formId, 'settings');
}

$form = DB::run(
    'SELECT id, name, schema_json, stats_team_field, stats_enumerator_field,
            qc_min_duration, qc_max_duration, qc_min_gap, qc_dup_min_answers, risk_min_n
     FROM forms WHERE id = ?',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

// Los umbrales viajan como enteros (minutos) o null.
$qcOut = fn(array $f): array => [
    'qc_min_duration' => $f['qc_min_duration'] !== null ? (int) $f['qc_min_duration'] : null,
    'qc_max_duration' => $f['qc_max_duration'] !== null ? (int) $f['qc_max_duration'] : null,
    'qc_min_gap'      => $f['qc_min_gap'] !== null ? (int) $f['qc_min_gap'] : null,
];

// Sensibilidad de duplicados: nº de respuestas (no minutos) o null = desactivada.
$dupOut = fn(array $f): ?int => $f['qc_dup_min_answers'] !== null ? (int) $f['qc_dup_min_answers'] : null;

// N mínimo del índice de riesgo (opt-in): nº de encuestas o null = desactivado.
$riskOut = fn(array $f): ?int => $f['risk_min_n'] !== null ? (int) $f['risk_min_n'] : null;

if ($method === 'GET') {
    ErrorResponse::ok([
        'id'                     => (int) $form['id'],
        'name'                   => $form['name'],
        'stats_team_field'       => $form['stats_team_field'],
        'stats_enumerator_field' => $form['stats_enumerator_field'],
        'qc_dup_min_answers'     => $dupOut($form),
        'risk_min_n'             => $riskOut($form),
    ] + $qcOut($form));
}

if ($method === 'PATCH') {
    $body = Request::json();

    // Rutas válidas: las del esquema del formulario (clave tal como aparece en el envío).
    // Los ejes de agrupación (equipo y encuestador) deben ser MONOVALUADOS: una fila
    // pertenece a un solo equipo/encuestador, así que se rechaza `select_multiple` (una
    // fila multivalor caería en varios grupos). Se sigue admitiendo texto/metadatos
    // monovaluados (p. ej. un código de equipo escrito), que no son select_one.
    $schema    = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
    $fieldsMeta = $schema['fields'] ?? [];

    // Normaliza una entrada a una ruta del esquema o null. Cadena vacía → null.
    $clean = function ($v) use ($fieldsMeta): ?string {
        if ($v === null) return null;
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!isset($fieldsMeta[$v])) {
            ErrorResponse::send('VALIDATION_ERROR', "Campo no válido: $v");
        }
        if (!empty($fieldsMeta[$v]['multi'])) {
            ErrorResponse::send('VALIDATION_ERROR', "El campo no puede ser de opción múltiple: $v");
        }
        return $v;
    };
    // Normaliza un umbral a minutos (entero 1–10080, una semana) o null.
    // '' y 0 → null (comprobación desactivada): un 0 «sin umbral» es lo que un
    // operador espera al vaciar el campo con el teclado numérico.
    $cleanMin = function ($v, string $name): ?int {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v) || (int) $v != $v || (int) $v < 0 || (int) $v > 10080) {
            ErrorResponse::send('VALIDATION_ERROR', "Umbral no válido ($name): minutos entre 1 y 10080, 0 o vacío");
        }
        $n = (int) $v;
        return $n === 0 ? null : $n;
    };

    // Clave ausente = conservar el valor actual (permite PATCH parciales).
    $team = array_key_exists('stats_team_field', $body) ? $clean($body['stats_team_field']) : $form['stats_team_field'];
    $enum = array_key_exists('stats_enumerator_field', $body) ? $clean($body['stats_enumerator_field']) : $form['stats_enumerator_field'];
    $qc   = $qcOut($form);
    foreach (array_keys($qc) as $k) {
        if (array_key_exists($k, $body)) $qc[$k] = $cleanMin($body[$k], $k);
    }
    if ($qc['qc_min_duration'] !== null && $qc['qc_max_duration'] !== null
        && $qc['qc_max_duration'] < $qc['qc_min_duration']) {
        ErrorResponse::send('VALIDATION_ERROR', 'La duración mayor admisible no puede ser menor que la menor');
    }

    // Sensibilidad de duplicados: nº de respuestas de contenido (1–50, tope holgado),
    // 0 o vacío = señal desactivada. No es un umbral en minutos, así que se valida aparte.
    $dup = $dupOut($form);
    if (array_key_exists('qc_dup_min_answers', $body)) {
        $v = $body['qc_dup_min_answers'];
        if ($v === null || $v === '') {
            $dup = null;
        } elseif (!is_numeric($v) || (int) $v != $v || (int) $v < 0 || (int) $v > 50) {
            ErrorResponse::send('VALIDATION_ERROR', 'Sensibilidad de duplicados no válida: nº de respuestas entre 1 y 50, 0 o vacío');
        } else {
            $dup = (int) $v === 0 ? null : (int) $v;
        }
    }

    // Índice de riesgo: N mínimo de encuestas por encuestador/equipo (1–100000),
    // 0 o vacío = índice desactivado (opt-in).
    $risk = $riskOut($form);
    if (array_key_exists('risk_min_n', $body)) {
        $v = $body['risk_min_n'];
        if ($v === null || $v === '') {
            $risk = null;
        } elseif (!is_numeric($v) || (int) $v != $v || (int) $v < 0 || (int) $v > 100000) {
            ErrorResponse::send('VALIDATION_ERROR', 'N mínimo del índice de riesgo no válido: entero entre 1 y 100000, 0 o vacío');
        } else {
            $risk = (int) $v === 0 ? null : (int) $v;
        }
    }

    DB::run(
        'UPDATE forms SET stats_team_field = ?, stats_enumerator_field = ?,
                qc_min_duration = ?, qc_max_duration = ?, qc_min_gap = ?, qc_dup_min_answers = ?,
                risk_min_n = ?
         WHERE id = ?',
        [$team, $enum, $qc['qc_min_duration'], $qc['qc_max_duration'], $qc['qc_min_gap'], $dup, $risk, $formId]
    );
    $out = [
        'stats_team_field'       => $team,
        'stats_enumerator_field' => $enum,
        'qc_dup_min_answers'     => $dup,
        'risk_min_n'             => $risk,
    ] + $qc;
    Audit::log($user['id'], 'update_form_stats', $formId, null, $out);
    ErrorResponse::ok($out);
}

if ($method !== 'DELETE') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

// El borrado hace cascade sobre submissions_cache, user_form_permissions y notification_config.
DB::run('DELETE FROM forms WHERE id = ?', [$formId]);

Audit::log($user['id'], 'delete_form', $formId, null, ['name' => $form['name']]);
ErrorResponse::ok(['deleted' => true]);
