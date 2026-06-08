<?php
/**
 * GET /api/v1/review-statuses   (cualquier usuario autenticado)
 * Catálogo de estados de revisión ACTIVOS, para que el frontend (badges, botones de
 * revisión, filtros, stats) pinte etiquetas y colores sin hardcodear los estados.
 */

Auth::require();

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

ErrorResponse::ok([
    'statuses' => ReviewStatus::active(),
    'colors'   => ReviewStatus::COLORS,
]);
