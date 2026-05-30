<?php
/**
 * POST /api/v1/auth/logout
 * Borra la sesión actual y limpia la cookie.
 */

if (Request::method() !== 'POST') {
    ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
}

Auth::logout();
ErrorResponse::ok(['loggedOut' => true]);
