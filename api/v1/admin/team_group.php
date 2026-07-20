<?php
/**
 * GET /api/v1/admin/forms/{id}/team-group   (admin, o permiso «Ajustes»)
 *
 * Algoritmo de los botones del «Campo de agrupación de equipos» (meta-equipo)
 * de la pantalla de ajustes. Solo lectura; el cálculo vive en lib/TeamGroups.
 *
 *   - Sin `?field=`  → «Detectar meta-equipos»: candidatos select_one rankeados
 *     por dependencia funcional equipo → F + más grueso que el equipo.
 *   - Con `?field=X` → «Detectar problemas»: valor dominante por equipo del campo
 *     elegido y lista de conflictos (aviso de calidad de dato).
 *
 * Con datos insuficientes responde `insufficient: true` (informa, no bloquea).
 */

$user   = Auth::require();
$formId = (int) Request::param('id');

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}
Auth::requireForm($user, $formId, 'settings');

$form = DB::run(
    'SELECT id, schema_json, stats_team_field, stats_enumerator_field FROM forms WHERE id = ?',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}
if (($form['stats_team_field'] ?? '') === '') {
    ErrorResponse::send('VALIDATION_ERROR', 'Configura antes el campo de equipo');
}

$schemaRaw  = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$scope      = RowScope::ruleForUser($user, $formId);
$fieldScope = FieldScope::ruleForUser($user, $formId);
$teamField  = (string) $form['stats_team_field'];

$field = trim((string) ($_GET['field'] ?? ''));
if ($field === '') {
    ErrorResponse::ok(TeamGroups::suggest(
        $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
        $teamField, $form['stats_enumerator_field'] ?: null
    ));
}

// Modo «Detectar problemas»: el campo debe existir en el esquema y ser visible.
$fieldsMeta = $schemaRaw['fields'] ?? [];
if (!isset($fieldsMeta[$field]) || FieldScope::isHidden($fieldScope, $field)) {
    ErrorResponse::send('VALIDATION_ERROR', "Campo no válido: $field");
}

ErrorResponse::ok(TeamGroups::check(
    $formId, $schemaRaw, $scope, $fieldScope, $user['locale'], $teamField, $field
));
