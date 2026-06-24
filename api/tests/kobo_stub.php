<?php
/**
 * Stub de la API de KoboToolbox para los tests de integración HTTP.
 *
 * Se sirve con `php -S 127.0.0.1:<puerto> tests/kobo_stub.php`; el `server_url` de la
 * cuenta Kobo de prueba apunta aquí, de modo que `KoboClient::editSubmission`
 * (PATCH /api/v2/assets/{uid}/data/bulk/) hable con este stub en vez de con Kobo real.
 *
 * Reproduce el contrato verificado contra Kobo real (TAREA 1):
 *   - una edición crea una versión NUEVA con un `_uuid` distinto → devuelve `uuid` nuevo
 *     y `root_uuid` = el envío editado;
 *   - responde HTTP 200 aunque la edición falle (detalle en `failures`/`status_code`).
 * Para forzar un fallo en un test, incluir la clave `force_fail` en `data`.
 */

declare(strict_types=1);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// PATCH /api/v2/assets/{uid}/data/bulk/
if ($method === 'PATCH' && preg_match('#/api/v2/assets/[^/]+/data/bulk/?$#', $path)) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids  = $body['payload']['submission_ids'] ?? [];
    $data = $body['payload']['data'] ?? [];

    if (!is_array($ids) || count($ids) === 0) {
        http_response_code(400);
        echo json_encode(['payload' => ['`submission_ids` must contain at least one value']]);
        exit;
    }

    // Fallo forzado por-envío (HTTP 200 con failures>0), como hace Kobo en errores parciales.
    if (array_key_exists('force_fail', $data)) {
        echo json_encode([
            'count' => 1, 'successes' => 0, 'failures' => 1,
            'results' => [[
                'uuid' => uuid4(), 'root_uuid' => (string) $ids[0],
                'status_code' => 400, 'message' => 'stub forced failure',
            ]],
        ]);
        exit;
    }

    echo json_encode([
        'count' => 1, 'successes' => 1, 'failures' => 0,
        'results' => [[
            'uuid' => uuid4(), 'root_uuid' => (string) $ids[0],
            'status_code' => 201, 'message' => 'Successful submission',
        ]],
    ]);
    exit;
}

// PATCH /api/v2/assets/{uid}/data/validation_statuses/  (push del estado de validación)
if ($method === 'PATCH' && preg_match('#/api/v2/assets/[^/]+/data/validation_statuses/?$#', $path)) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids  = $body['payload']['submission_ids'] ?? [];

    if (!is_array($ids) || count($ids) === 0) {
        http_response_code(400);
        echo json_encode(['detail' => '`submission_ids` is required']);
        exit;
    }
    // _id 4030 simula falta del permiso «Validate Submissions» (HTTP 403).
    if (in_array(4030, array_map('intval', $ids), true)) {
        http_response_code(403);
        echo json_encode(['detail' => 'You do not have permission to perform this action.']);
        exit;
    }
    // Fallo forzado: el _id 9999 simula que Kobo rechaza el cambio (HTTP 200 con failures>0).
    if (in_array(9999, array_map('intval', $ids), true)) {
        echo json_encode(['successes' => 0, 'failures' => 1, 'detail' => 'stub forced validation failure']);
        exit;
    }
    echo json_encode(['successes' => count($ids), 'failures' => 0, 'detail' => 'ok']);
    exit;
}

// DELETE /api/v2/assets/{uid}/data/{id}/validation_status/  (limpiar a «sin estado»)
if ($method === 'DELETE' && preg_match('#/api/v2/assets/[^/]+/data/\d+/validation_status/?$#', $path)) {
    http_response_code(204);
    exit;
}

http_response_code(404);
echo json_encode(['detail' => 'stub: ruta no soportada ' . $method . ' ' . $path]);
