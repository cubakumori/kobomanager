<?php
/**
 * /api/v1/forms/{id}/team-conflicts — incongruencias equipo ↔ meta-equipo desde
 * el Control de calidad (lib/TeamConflicts). Solo con `stats_team_field` Y
 * `team_group_field` configurados; si no, GET responde `enabled: false` (la
 * tarjeta del QC se oculta sola, sin error).
 *
 *   GET  → plan de resolución (permiso «Ajustes», como admin/forms/{id}/team-group:
 *          los conflictos revelan la estructura equipo↔meta completa). `can_apply`
 *          viaja en la respuesta para que la UI sepa si ofrecer la resolución.
 *   POST → aplica una tanda de correcciones YA CONFIRMADA por el usuario
 *          (requiere can_edit; en demo está bloqueado — escribe en Kobo real).
 *          Body: { changes: [ { uid, field: 'team'|'meta', value }, … ] } (≤ 50).
 *          `field` se traduce a la ruta real del formulario; cada cambio pasa por
 *          el flujo de edición real (lib/SubmissionEdit: PATCH a Kobo, migración
 *          de _uuid, arrastre de revisiones, auditoría). Un fallo en un envío no
 *          aborta la tanda: se devuelve applied/failed por envío.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');
$method = Request::method();

if ($method !== 'GET' && $method !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}
Auth::requireForm($user, $formId, $method === 'GET' ? 'settings' : 'edit');

$form = DB::run(
    'SELECT id, name, schema_json, deployment_status, kobo_asset_uid, kobo_account_id,
            stats_team_field, stats_enumerator_field, team_group_field, team_conflict_mode
     FROM forms WHERE id = ? AND active = 1',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

$teamField  = (string) ($form['stats_team_field'] ?? '');
$groupField = (string) ($form['team_group_field'] ?? '');
$fieldScope = FieldScope::ruleForUser($user, $formId);

// Sin la cadena equipo → meta-equipo configurada (o con alguno de los dos ejes
// oculto para este usuario) no hay detección: la tarjeta no se muestra.
$enabled = $teamField !== '' && $groupField !== ''
    && !FieldScope::isHidden($fieldScope, $teamField)
    && !FieldScope::isHidden($fieldScope, $groupField);

$scope     = RowScope::ruleForUser($user, $formId);
$schemaRaw = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$mode      = TeamConflicts::mode($form['team_conflict_mode'] ?? null);

// ---------- GET: plan de resolución ----------
if ($method === 'GET') {
    if (!$enabled) {
        ErrorResponse::ok(['enabled' => false, 'mode' => $mode]);
    }
    $plan = TeamConflicts::plan(
        $formId, $schemaRaw, $scope, $fieldScope, $user['locale'],
        $teamField, $form['stats_enumerator_field'] ?: null, $groupField, $mode
    );
    ErrorResponse::ok(array_merge([
        'enabled'   => true,
        // Resolver = editar envíos de verdad: mismo requisito que el detalle, más el
        // candado de solo-lectura de los archivados que ya aplica la página de QC.
        'can_apply' => Auth::canForm($user, $formId, 'edit') && ($form['deployment_status'] ?? null) !== 'archived',
    ], $plan));
}

// ---------- POST: aplicar una tanda confirmada ----------
if (!$enabled) {
    ErrorResponse::send('VALIDATION_ERROR', 'La resolución requiere campo de equipo y de agrupación configurados y visibles');
}

$changes = Request::json()['changes'] ?? null;
if (!is_array($changes) || $changes === [] || count($changes) > 50) {
    ErrorResponse::send('VALIDATION_ERROR', 'Faltan cambios a aplicar (changes, máximo 50 por tanda)');
}

// `field` lógico → ruta real. El meta-equipo solo se corrige como opción manual
// (caso raro), pero pasa por la misma validación.
$paths = ['team' => $teamField, 'meta' => $groupField];
$clean = [];
foreach ($changes as $i => $ch) {
    $field = (string) ($ch['field'] ?? '');
    $uid   = trim((string) ($ch['uid'] ?? ''));
    $value = $ch['value'] ?? null;
    if (!isset($paths[$field]) || $uid === '' || !is_string($value) || trim($value) === '' || mb_strlen($value) > 255) {
        ErrorResponse::send('VALIDATION_ERROR', "Cambio no válido (posición $i): uid, field 'team'|'meta' y value son obligatorios");
    }
    $path = $paths[$field];
    // Mismos vetos que la edición individual: campo visible y editable para este usuario.
    if (FieldScope::isHidden($fieldScope, $path) || FieldScope::isReadonly($fieldScope, $path)) {
        ErrorResponse::send('VALIDATION_ERROR', "Campo de solo lectura u oculto: $path");
    }
    $clean[] = ['uid' => $uid, 'path' => $path, 'value' => trim($value)];
}

// Cuenta + token para escribir en Kobo (un solo cliente para toda la tanda).
$acc = DB::run(
    'SELECT server_url, api_token FROM kobo_accounts WHERE id = ?',
    [$form['kobo_account_id']]
)->fetch();
if (!$acc) {
    ErrorResponse::send('KOBO_ACCOUNT_DISABLED', 'La cuenta Kobo no existe');
}
$client = new KoboClient($acc['server_url'], TokenVault::decrypt($acc['api_token']));

$applied = 0;
$failed  = [];  // [{uid, error}]
$uidMap  = [];  // uid original => uid vigente tras la edición (cambia en cada edición)
foreach ($clean as $ch) {
    $sub = DB::run(
        'SELECT sc.id, sc.submission_uid, sc.json_payload, f.kobo_asset_uid, f.schema_json
         FROM submissions_cache sc JOIN forms f ON f.id = sc.form_id
         WHERE sc.submission_uid = ? AND sc.form_id = ?',
        [$ch['uid'], $formId]
    )->fetch();
    // Fuera del alcance por filas = inexistente (mismo trato que el detalle).
    if (!$sub || !RowScope::matches($scope, json_decode($sub['json_payload'], true) ?: [])) {
        $failed[] = ['uid' => $ch['uid'], 'error' => 'NOT_FOUND'];
        continue;
    }
    $payload = json_decode($sub['json_payload'], true) ?: [];
    if ((string) ($payload[$ch['path']] ?? '') === $ch['value']) {
        // Ya tiene el valor pedido (tanda repetida/carrera): no-op contado como aplicado.
        $applied++;
        $uidMap[$ch['uid']] = $sub['submission_uid'];
        continue;
    }
    try {
        $res = SubmissionEdit::apply($sub, [$ch['path'] => $ch['value']], $client, (int) $user['id'], $formId);
        $applied++;
        $uidMap[$ch['uid']] = $res['submission_uid'];
    } catch (KoboException $e) {
        $failed[] = ['uid' => $ch['uid'], 'error' => $e->errorCode];
    } catch (RuntimeException $e) {
        // Kobo aceptó pero la caché local falló: el cambio es real, hay que resincronizar.
        $failed[] = ['uid' => $ch['uid'], 'error' => 'CACHE_STALE'];
    }
}

// Resumen de la tanda en el registro de auditoría (cada edición ya dejó su
// entrada 'edit' con before/after vía SubmissionEdit).
Audit::log($user['id'], 'resolve_team_conflicts', $formId, null, [
    'mode' => $mode, 'requested' => count($clean), 'applied' => $applied, 'failed' => count($failed),
]);

ErrorResponse::ok([
    'applied' => $applied,
    'failed'  => $failed,
    'uid_map' => $uidMap,
]);
