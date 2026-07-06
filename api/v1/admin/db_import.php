<?php
/**
 * /api/v1/admin/db/import   (solo admin; bloqueado en demo)
 *   POST multipart (campo `file`) → restaura una copia de seguridad generada por
 *   el export (ver lib/DbBackup): valida cabecera/alcance/contenido ANTES de
 *   tocar la BD y restaura en UNA transacción (fallo a mitad → ROLLBACK).
 *
 * Límite de tamaño = upload_max_filesize/post_max_size de PHP (documentado en
 * DEPLOY §11). Tras un restore 'full', si el usuario actual no viaja en el
 * backup su sesión queda purgada: la siguiente petición devolverá 401 y la UI
 * volverá al login (avisado en el modal de confirmación).
 */

$admin = Auth::requireAdmin();

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

// Si el cuerpo supera post_max_size, PHP lo descarta ENTERO ($_FILES y $_POST
// llegan vacíos, sin código de error): detectarlo por Content-Length para dar
// un mensaje accionable en vez de «no se recibió ningún archivo».
// (ini_parse_quantity es PHP 8.2+; el proyecto soporta 8.1 → conversión propia.)
$iniBytes = static function (string $v): int {
    $n = (int) trim($v);
    return match (strtoupper(substr(trim($v), -1))) {
        'G' => $n * 1073741824, 'M' => $n * 1048576, 'K' => $n * 1024, default => $n,
    };
};
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postLimit     = $iniBytes((string) ini_get('post_max_size'));
if ($contentLength > 0 && $postLimit > 0 && $contentLength > $postLimit && !$_FILES && !$_POST) {
    ErrorResponse::send('VALIDATION_ERROR', sprintf(
        'El archivo (%s) supera el límite de subida de PHP (post_max_size=%s / upload_max_filesize=%s). Súbelo en php.ini o en el panel del hosting.',
        round($contentLength / 1048576, 1) . ' MB', ini_get('post_max_size'), ini_get('upload_max_filesize')
    ));
}

$file = $_FILES['file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    // UPLOAD_ERR_INI_SIZE/FORM_SIZE = archivo mayor que upload_max_filesize.
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? sprintf('El archivo supera el límite de subida de PHP (upload_max_filesize=%s).', ini_get('upload_max_filesize'))
        : 'No se recibió ningún archivo (campo «file»).';
    ErrorResponse::send('VALIDATION_ERROR', $msg);
}
if (!is_uploaded_file($file['tmp_name'])) {
    ErrorResponse::send('VALIDATION_ERROR', 'Subida no válida.');
}

$sql = (string) file_get_contents($file['tmp_name']);
if ($sql === '') {
    ErrorResponse::send('VALIDATION_ERROR', 'El archivo está vacío.');
}

try {
    $res = DbBackup::import($sql);
} catch (PDOException $e) {
    // OJO: PDOException EXTIENDE RuntimeException — debe capturarse antes.
    // Fallo a mitad (esquema divergente…): ROLLBACK hecho, estado anterior intacto.
    // Endpoint solo-admin: el mensaje es accionable y no revela nada ajeno.
    ErrorResponse::send('INTERNAL_ERROR', 'La restauración falló y se revirtió: ' . $e->getMessage());
} catch (RuntimeException $e) {
    // Archivo que no valida (cabecera ajena, semilla de demo, tabla fuera de
    // alcance…): error de entrada, la BD no se ha tocado.
    ErrorResponse::send('VALIDATION_ERROR', $e->getMessage());
} catch (Throwable $e) {
    ErrorResponse::send('INTERNAL_ERROR', 'La restauración falló y se revirtió: ' . $e->getMessage());
}

Audit::log($admin['id'], 'db_import', null, null, $res);

ErrorResponse::ok($res);
