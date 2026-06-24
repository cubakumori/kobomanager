<?php
/**
 * /api/v1/admin/forms/{id}   (solo admin)
 *
 *   GET    → datos editables del formulario (hoy: la config del desglose de
 *            estadísticas por equipo → encuestador).
 *   PATCH  { stats_team_field, stats_enumerator_field } → guarda esa config.
 *            Cada campo es una ruta del esquema o null. `stats_enumerator_field`
 *            null = usar `_submitted_by` (el usuario Kobo que envió).
 *   DELETE → elimina el formulario de KoboManager y su caché (no toca Kobo).
 *            Si sigue cumpliendo el filtro de sincronización, una nueva
 *            sincronización de la cuenta volverá a traerlo.
 */

$admin  = Auth::requireAdmin();
$formId = (int) Request::param('id');
$method = Request::method();

$form = DB::run(
    'SELECT id, name, schema_json, stats_team_field, stats_enumerator_field FROM forms WHERE id = ?',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

if ($method === 'GET') {
    ErrorResponse::ok([
        'id'                     => (int) $form['id'],
        'name'                   => $form['name'],
        'stats_team_field'       => $form['stats_team_field'],
        'stats_enumerator_field' => $form['stats_enumerator_field'],
    ]);
}

if ($method === 'PATCH') {
    $body = Request::json();

    // Rutas válidas: las del esquema del formulario (clave tal como aparece en el envío).
    $schema    = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
    $validKeys = array_map('strval', array_keys($schema['fields'] ?? []));

    // Normaliza una entrada a una ruta del esquema o null. Cadena vacía → null.
    $clean = function ($v) use ($validKeys): ?string {
        if ($v === null) return null;
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!in_array($v, $validKeys, true)) {
            ErrorResponse::send('VALIDATION_ERROR', "Campo no válido: $v");
        }
        return $v;
    };

    $team = $clean($body['stats_team_field'] ?? null);
    $enum = $clean($body['stats_enumerator_field'] ?? null);

    DB::run(
        'UPDATE forms SET stats_team_field = ?, stats_enumerator_field = ? WHERE id = ?',
        [$team, $enum, $formId]
    );
    Audit::log($admin['id'], 'update_form_stats', $formId, null, [
        'stats_team_field'       => $team,
        'stats_enumerator_field' => $enum,
    ]);
    ErrorResponse::ok([
        'stats_team_field'       => $team,
        'stats_enumerator_field' => $enum,
    ]);
}

if ($method !== 'DELETE') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

// El borrado hace cascade sobre submissions_cache, user_form_permissions y notification_config.
DB::run('DELETE FROM forms WHERE id = ?', [$formId]);

Audit::log($admin['id'], 'delete_form', $formId, null, ['name' => $form['name']]);
ErrorResponse::ok(['deleted' => true]);
