<?php
/**
 * GET /api/v1/auth/me
 * Devuelve el usuario autenticado actual.
 */

$user = Auth::require();
// Se adjunta el flag global que gobierna el menú «Mi actividad» para que el
// frontend pueda mostrarlo/ocultarlo sin una petición adicional.
$user['audit_self_view_enabled'] = Settings::auditSelfViewEnabled();
ErrorResponse::ok($user);
