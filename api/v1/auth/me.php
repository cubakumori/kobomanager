<?php
/**
 * GET /api/v1/auth/me
 * Devuelve el usuario autenticado actual.
 */

$user = Auth::require();
// Se adjunta el flag global que gobierna el menú «Mi actividad» para que el
// frontend pueda mostrarlo/ocultarlo sin una petición adicional.
$user['audit_self_view_enabled'] = Settings::auditSelfViewEnabled();
// Solo para admins: columnas que el código espera y faltan en la BD (deploy sin
// migrar). El front muestra un aviso accionable en vez de esperar a un 500 opaco.
if (($user['role'] ?? '') === 'admin') {
    $user['schema_missing'] = array_map(
        fn($m) => $m['table'] . '.' . $m['column'],
        SchemaCheck::missing()
    );
}
ErrorResponse::ok($user);
