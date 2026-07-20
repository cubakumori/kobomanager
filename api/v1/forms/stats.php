<?php
/**
 * GET /api/v1/forms/{id}/stats   (requiere can_view)
 * Estadísticas calculadas sobre submissions_cache (respetan el scoping por filas
 * y el ocultado de columnas). El cálculo vive en lib/Stats (compartido con el
 * endpoint público de enlaces compartidos); aquí solo se resuelven los permisos.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$form = DB::run('SELECT id, name, schema_json, deployment_status, stats_team_field, stats_enumerator_field, team_group_field, retention_days FROM forms WHERE id = ? AND active = 1', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
Auth::requireForm($user, $formId, 'view');

$scope      = RowScope::ruleForUser($user, $formId);
$fieldScope = FieldScope::ruleForUser($user, $formId);
$schemaRaw  = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;

// Filtro por estado de revisión: `?status=` lo fija explícitamente (al pulsar una
// tarjeta del encabezado); sin él, se aplica el alcance por defecto configurado
// globalmente ('all' o 'approved'). Cualquier valor no válido cae al por defecto.
$reqStatus = (string) ($_GET['status'] ?? '');
$filter    = in_array($reqStatus, ['all', 'pending', 'approved', 'on_hold', 'rejected'], true)
    ? $reqStatus
    : Settings::statsDefaultScope();

// Filtro por equipos (`?teams=` = claves separadas por coma; el bucket «sin equipo»
// usa '__none__'). Ausente = null = todos. Presente pero vacío = ninguno seleccionado.
$teamSel = array_key_exists('teams', $_GET)
    ? array_values(array_filter(explode(',', (string) $_GET['teams']), fn($s) => $s !== ''))
    : null;

// Rango de fechas opcional (`?from=` / `?to=`, YYYY-MM-DD inclusive, días UTC).
// La validación fina la hace Stats::compute (formato inválido = sin filtro).
$dateFrom = ($_GET['from'] ?? '') !== '' ? (string) $_GET['from'] : null;
$dateTo   = ($_GET['to'] ?? '') !== '' ? (string) $_GET['to'] : null;

// Agrupación por meta-equipo (`?group=1`, toggle «Agrupar equipos»): desplaza los
// ejes del desglose UN nivel — meta-equipo → equipos — reutilizando la estructura de
// dos niveles existente (aquí cada envío se agrega por SU PROPIO valor del campo de
// grupo, sin inferir dominantes: no hay plan por medio). Solo si el campo está
// configurado y es visible para este usuario; el filtro `?teams=` pasa a operar
// sobre claves de meta-equipo.
$groupField = $form['team_group_field'] ?: null;
$groupable  = $groupField !== null && ($form['stats_team_field'] ?: null) !== null
    && !FieldScope::isHidden($fieldScope, $groupField);
$grouped    = $groupable && (string) ($_GET['group'] ?? '') === '1';

[$axisTeam, $axisEnum] = $grouped
    ? [$groupField, $form['stats_team_field']]
    : [$form['stats_team_field'] ?: null, $form['stats_enumerator_field'] ?: null];

$stats = Stats::compute(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'], true,
    $axisTeam, $axisEnum,
    $filter, $teamSel, null, $dateFrom, $dateTo
);

ErrorResponse::ok(array_merge([
    'form'              => ['id' => (int) $form['id'], 'name' => $form['name']],
    'deployment_status' => $form['deployment_status'] ?? null,
    'team_group_configured' => $groupable,
    'team_grouped'      => $grouped,
    // Retención configurada (días o null): la vista avisa de que las métricas
    // cubren solo la ventana retenida en caché.
    'retention_days'    => $form['retention_days'] !== null ? (int) $form['retention_days'] : null,
], $stats));
