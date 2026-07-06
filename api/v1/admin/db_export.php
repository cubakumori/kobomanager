<?php
/**
 * /api/v1/admin/db/export?scope=full|settings   (solo admin; bloqueado en demo)
 *   GET → descarga una copia de seguridad SQL solo-datos (ver lib/DbBackup).
 *
 * Respuesta en STREAMING (attachment), no JSON: los errores previos salen por
 * ErrorResponse; una vez emitida la primera línea ya no hay marcha atrás (por
 * eso la auditoría se registra antes de emitir).
 */

$admin = Auth::requireAdmin();

if (Request::method() !== 'GET') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

$scope = (string) ($_GET['scope'] ?? 'full');
if (!in_array($scope, DbBackup::SCOPES, true)) {
    ErrorResponse::send('VALIDATION_ERROR', 'Alcance no válido (full|settings).');
}

Audit::log($admin['id'], 'db_export', null, null, ['scope' => $scope]);

$filename = sprintf('kobomanager-backup-%s-%s.sql', gmdate('Ymd-Hi'), $scope);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

DbBackup::export($scope, static function (string $chunk): void {
    echo $chunk;
});
exit;
