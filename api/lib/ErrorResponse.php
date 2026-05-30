<?php
/**
 * Respuestas JSON estándar (éxito y error) según la sección 4.5 del plan.
 */
class ErrorResponse {
    /** Catálogo código => [http status, mensaje por defecto]. */
    private const CODES = [
        'KOBO_TIMEOUT'                  => [504, 'No se pudo contactar con el servidor de Kobo'],
        'KOBO_UNAUTHORIZED'             => [502, 'Token de Kobo expirado o inválido'],
        'KOBO_ACCOUNT_DISABLED'         => [403, 'La cuenta Kobo fue deshabilitada'],
        'KOBO_FORM_NOT_FOUND'           => [404, 'El formulario no existe en Kobo'],
        'KOBO_SUBMISSION_NOT_FOUND'     => [404, 'El envío no existe en Kobo'],
        'KOBO_RATE_LIMIT'               => [429, 'Se alcanzó el límite de peticiones de la API de Kobo'],
        'AUTH_INVALID_TOKEN'            => [401, 'Sesión inválida o expirada'],
        'AUTH_INSUFFICIENT_PERMISSIONS' => [403, 'No tienes permisos suficientes'],
        'AUTH_RATE_LIMITED'             => [429, 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.'],
        'VALIDATION_ERROR'              => [422, 'Datos de entrada inválidos'],
        'NOT_FOUND'                     => [404, 'Recurso no encontrado'],
        'INTERNAL_ERROR'                => [500, 'Error interno del servidor'],
    ];

    /** Envía una respuesta de error y termina la ejecución. */
    public static function send(string $code, ?string $message = null, ?int $httpStatus = null): never {
        [$status, $defaultMsg] = self::CODES[$code] ?? [500, 'Error interno del servidor'];
        http_response_code($httpStatus ?? $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message ?? $defaultMsg,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Envía una respuesta de éxito y termina la ejecución. */
    public static function ok(mixed $data = null, int $httpStatus = 200): never {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
