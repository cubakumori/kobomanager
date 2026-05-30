<?php
/**
 * GET /api/v1/auth/me
 * Devuelve el usuario autenticado actual.
 */

$user = Auth::require();
ErrorResponse::ok($user);
