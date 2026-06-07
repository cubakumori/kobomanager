<?php
/**
 * Utilidades para leer la entrada de la petición.
 */
class Request {
    private static ?array $jsonCache = null;

    /** Parámetros extraídos de la ruta dinámica (ej. {id}). Los fija el front controller. */
    public static array $params = [];

    public static function param(string $key, mixed $default = null): mixed {
        return self::$params[$key] ?? $default;
    }

    /** Cuerpo JSON de la petición como array asociativo. */
    /** Tope del cuerpo JSON (anti-DoS por memoria). Generoso para edición de envíos. */
    private const MAX_BODY_BYTES = 2 * 1024 * 1024; // 2 MB

    public static function json(): array {
        if (self::$jsonCache === null) {
            // Rechaza por Content-Length antes de leer; además acota la lectura para
            // no materializar en memoria un cuerpo enorme aunque la cabecera mienta.
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
                ErrorResponse::send('VALIDATION_ERROR', 'Cuerpo de la petición demasiado grande', 413);
            }
            $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1) ?: '';
            if (strlen($raw) > self::MAX_BODY_BYTES) {
                ErrorResponse::send('VALIDATION_ERROR', 'Cuerpo de la petición demasiado grande', 413);
            }
            $data = json_decode($raw, true);
            self::$jsonCache = is_array($data) ? $data : [];
        }
        return self::$jsonCache;
    }

    /** Exige que los campos indicados existan y no estén vacíos; corta con VALIDATION_ERROR si no. */
    public static function required(array $fields): array {
        $body = self::json();
        $out = [];
        foreach ($fields as $f) {
            $v = $body[$f] ?? null;
            if ($v === null || (is_string($v) && trim($v) === '')) {
                ErrorResponse::send('VALIDATION_ERROR', "Falta el campo obligatorio: $f");
            }
            $out[$f] = is_string($v) ? trim($v) : $v;
        }
        return $out;
    }

    public static function method(): string {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
}
