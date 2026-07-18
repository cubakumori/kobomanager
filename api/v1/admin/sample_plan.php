<?php
/**
 * /api/v1/admin/forms/{id}/sample-plan   (admin, o permiso «Muestra» del formulario;
 * jerárquico: «Muestra» implica «Ajustes», pero NO al revés — un usuario con solo
 * «Ajustes» edita QC/desglose y aquí recibe 403)
 *
 *   GET → configuración de la muestra (campo principal + secundarios + denominador)
 *         y el PLAN VIGENTE como matriz de objetivos por celda, más lo RECIBIDO por
 *         celda (según el denominador, alcance completo) para el reparto proporcional.
 *   PUT { sample_field, sample_field2?, sample_field3?, denominator,
 *         cells: [{ team_value, sample_value, target }] } → guarda la config y
 *         REEMPLAZA el plan vigente (sample_targets), archivando un snapshot completo
 *         en sample_target_history (el plan cambia en campaña; no se sobrescribe sin
 *         rastro). Solo se guardan celdas con objetivo > 0; el resto = «fuera de plan».
 *
 * El eje de EQUIPO reutiliza forms.stats_team_field (no se configura aquí): el plan
 * no tiene sentido sin un campo de equipo, así que el editor exige configurarlo antes.
 */

$user   = Auth::require();
$formId = (int) Request::param('id');
$method = Request::method();

Auth::requireForm($user, $formId, 'sample');

$form = DB::run(
    'SELECT id, name, schema_json, stats_team_field,
            sample_field, sample_field2, sample_field3, sample_denominator
     FROM forms WHERE id = ?',
    [$formId]
)->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

$DENOMINATORS = ['approved', 'approved_pending'];

if ($method === 'GET') {
    // Objetivos vigentes: mapa "equipo|valor" => objetivo.
    $targets = [];
    foreach (DB::run('SELECT team_value, sample_value, target FROM sample_targets WHERE form_id = ?', [$formId])->fetchAll() as $r) {
        $targets[$r['team_value'] . '|' . $r['sample_value']] = (int) $r['target'];
    }

    // Recibido por celda (alcance COMPLETO, según el denominador guardado): alimenta el
    // reparto «proporcional a lo recibido» del editor. Solo si ya hay campo de muestreo.
    $received = [];
    $sampleValues = [];
    $teamValues = [];
    if (($form['sample_field'] ?? '') !== '') {
        $schemaRaw = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
        $s = Sample::compute(
            $formId, $schemaRaw, null, null, $user['locale'],
            $form['stats_team_field'] ?: null,
            (string) $form['sample_field'],
            (string) $form['sample_denominator']
        );
        $sampleValues = $s['values'];
        foreach ($s['teams'] as $t) {
            $teamValues[] = ['value' => $t['key'], 'label' => $t['name']];
            foreach ($t['cells'] as $c) {
                $received[$t['key'] . '|' . $c['value']] = $c['done'];
            }
        }
    }

    ErrorResponse::ok([
        'name'                   => $form['name'],
        'team_field'             => $form['stats_team_field'],
        'team_field_configured'  => ($form['stats_team_field'] ?? '') !== '',
        'sample_field'           => $form['sample_field'],
        'sample_field2'          => $form['sample_field2'],
        'sample_field3'          => $form['sample_field3'],
        'denominator'            => $form['sample_denominator'] ?: 'approved',
        'targets'                => $targets,
        'received'               => $received,
        'team_values'            => $teamValues,
        'sample_values'          => $sampleValues,
    ]);
}

if ($method !== 'PUT') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$body = Request::json();

// Rutas válidas: claves del esquema del formulario. El campo de muestreo (principal y
// secundarios) DEBE ser de opción única (`select_one`): sus valores son un conjunto
// cerrado y conocido que forma las columnas del plan / la distribución, y cada fila cae
// en un solo valor. Un `select_multiple` rompería la partición (una fila en varias
// celdas); un campo de texto daría valores ilimitados sin objetivo por columna posible.
$schema    = $form['schema_json'] ? json_decode($form['schema_json'], true) : null;
$fieldsMeta = $schema['fields'] ?? [];
$cleanField = function ($v, bool $required) use ($fieldsMeta): ?string {
    if ($v === null || (is_string($v) && trim($v) === '')) {
        if ($required) ErrorResponse::send('VALIDATION_ERROR', 'Falta el campo de muestreo principal');
        return null;
    }
    $v = trim((string) $v);
    if (!isset($fieldsMeta[$v])) {
        ErrorResponse::send('VALIDATION_ERROR', "Campo no válido: $v");
    }
    $type = (string) ($fieldsMeta[$v]['type'] ?? '');
    if (!str_starts_with($type, 'select_one')) {
        ErrorResponse::send('VALIDATION_ERROR', "El campo de muestreo debe ser de opción única: $v");
    }
    return $v;
};

$sampleField  = $cleanField($body['sample_field'] ?? null, true);
$sampleField2 = $cleanField($body['sample_field2'] ?? null, false);
$sampleField3 = $cleanField($body['sample_field3'] ?? null, false);

// Los tres campos deben ser DISTINTOS (un campo no puede ser a la vez principal y
// secundario, ni repetirse entre secundarios). El editor ya los excluye mutuamente;
// esto cierra la vía API.
$chosen = array_filter([$sampleField, $sampleField2, $sampleField3], fn($v) => $v !== null);
if (count($chosen) !== count(array_unique($chosen))) {
    ErrorResponse::send('VALIDATION_ERROR', 'Los campos de muestreo (principal y secundarios) deben ser distintos');
}

$denominator = (string) ($body['denominator'] ?? 'approved');
if (!in_array($denominator, $DENOMINATORS, true)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Denominador no válido');
}

// Celdas: normaliza y descarta objetivos <= 0 (ausencia = «fuera de plan»). Deduplica
// por (equipo, valor) quedándose con el último; cada valor y equipo es una cadena.
$rawCells = $body['cells'] ?? [];
if (!is_array($rawCells)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Formato de celdas no válido');
}
$cellMap = [];
foreach ($rawCells as $c) {
    if (!is_array($c)) continue;
    $tv = isset($c['team_value']) ? (string) $c['team_value'] : '';
    $sv = isset($c['sample_value']) ? (string) $c['sample_value'] : '';
    if ($tv === '' || $sv === '') continue;
    $tg = $c['target'] ?? 0;
    if (!is_numeric($tg) || (int) $tg != $tg || (int) $tg < 0 || (int) $tg > 100000000) {
        ErrorResponse::send('VALIDATION_ERROR', 'Objetivo no válido: entero entre 0 y 100000000');
    }
    $tg = (int) $tg;
    if ($tg > 0) $cellMap[$tv . '|' . $sv] = ['team_value' => $tv, 'sample_value' => $sv, 'target' => $tg];
}
$cells = array_values($cellMap);

// Transacción: guarda la config, reemplaza el plan vigente y archiva el snapshot.
$pdo = DB::conn();
$pdo->beginTransaction();
try {
    DB::run(
        'UPDATE forms SET sample_field = ?, sample_field2 = ?, sample_field3 = ?, sample_denominator = ? WHERE id = ?',
        [$sampleField, $sampleField2, $sampleField3, $denominator, $formId]
    );
    DB::run('DELETE FROM sample_targets WHERE form_id = ?', [$formId]);
    foreach ($cells as $c) {
        DB::run(
            'INSERT INTO sample_targets (form_id, team_value, sample_value, target) VALUES (?, ?, ?, ?)',
            [$formId, $c['team_value'], $c['sample_value'], $c['target']]
        );
    }
    $snapshot = [
        'field'       => $sampleField,
        'field2'      => $sampleField2,
        'field3'      => $sampleField3,
        'denominator' => $denominator,
        'cells'       => $cells,
    ];
    DB::run(
        'INSERT INTO sample_target_history (form_id, payload_json) VALUES (?, ?)',
        [$formId, json_encode($snapshot, JSON_UNESCAPED_UNICODE)]
    );
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

Audit::log($user['id'], 'update_sample_plan', $formId, null, [
    'sample_field' => $sampleField,
    'denominator'  => $denominator,
    'cells'        => count($cells),
]);

ErrorResponse::ok([
    'sample_field'  => $sampleField,
    'sample_field2' => $sampleField2,
    'sample_field3' => $sampleField3,
    'denominator'   => $denominator,
    'cells'         => count($cells),
]);
