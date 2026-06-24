<?php
/**
 * GET /api/v1/public/share/{token}/stats   (PÚBLICO, sin sesión)
 * Estadísticas del enlace, con su filtro de filas y ocultado de columnas
 * aplicados. NO expone el estado de revisión interno (`by_status` y la mezcla de
 * revisión de `by_team` se omiten con includeReview=false), coherente con el resto
 * de la vista pública de solo lectura.
 */

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$token = (string) Request::param('token');
$link  = ShareLink::requireAccess($token, 'stats');

$formId     = (int) $link['form_id'];
$schemaRaw  = $link['schema_json'] ? json_decode($link['schema_json'], true) : null;
$scope      = ShareLink::rule($link);
$fieldScope = FieldScope::ruleForLink($link);

$stats = Stats::compute(
    $formId, $schemaRaw, $scope, $fieldScope, Settings::defaultLocale(), false,
    $link['stats_team_field'] ?: null, $link['stats_enumerator_field'] ?: null
);

ErrorResponse::ok(array_merge([
    'form'              => ['name' => $link['form_name']],
    'deployment_status' => $link['deployment_status'] ?? null,
], $stats));
