<?php
/**
 * /api/v1/admin/review-statuses   (solo admin)
 *   GET  → catálogo completo (incluye inactivos) + paleta de colores.
 *   POST → crea un estado personalizado { label, color, is_open }.
 *
 * Los built-ins (pending/on_hold/approved/rejected) no se crean aquí: ya están
 * sembrados. Las claves personalizadas se derivan del label (slug único) y son
 * inmutables (las referencian las filas de submission_reviews).
 */

$admin = Auth::requireAdmin();

if (Request::method() === 'GET') {
    ErrorResponse::ok([
        'statuses' => ReviewStatus::all(),
        'colors'   => ReviewStatus::COLORS,
        'builtins' => ReviewStatus::BUILTINS,
    ]);
}

if (Request::method() === 'POST') {
    $body  = Request::json();
    $label = trim((string) ($body['label'] ?? ''));
    $color = (string) ($body['color'] ?? 'slate');
    $open  = (bool) ($body['is_open'] ?? true);

    if ($label === '') {
        ErrorResponse::send('VALIDATION_ERROR', 'El nombre del estado es obligatorio');
    }
    if (mb_strlen($label) > 64) {
        ErrorResponse::send('VALIDATION_ERROR', 'El nombre del estado es demasiado largo (máx. 64)');
    }
    if (!in_array($color, ReviewStatus::COLORS, true)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Color no válido');
    }

    // Clave estable derivada del nombre (slug), única y nunca igual a un built-in.
    $base = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label));
    $base = trim((string) $base, '_');
    if ($base === '' || in_array($base, ReviewStatus::BUILTINS, true)) {
        $base = 'status';
    }
    $base = mb_substr($base, 0, 28);
    $key  = $base;
    $existing = array_flip(ReviewStatus::keys());
    $n = 2;
    while (isset($existing[$key])) {
        $key = $base . '_' . $n++;
    }

    $order = (int) (DB::run('SELECT COALESCE(MAX(sort_order), 0) AS m FROM review_statuses')->fetch()['m'] ?? 0) + 10;

    DB::run(
        'INSERT INTO review_statuses (status_key, label, color, is_open, is_builtin, sort_order, active)
         VALUES (?, ?, ?, ?, 0, ?, 1)',
        [$key, $label, $color, $open ? 1 : 0, $order]
    );
    ReviewStatus::flush();

    Audit::log($admin['id'], 'review_status_create', null, null, ['key' => $key, 'label' => $label]);
    ErrorResponse::ok(['key' => $key, 'label' => $label, 'color' => $color, 'is_open' => $open], 201);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
