<?php
/**
 * /api/v1/admin/review-statuses/{id}   (solo admin)
 *   PUT    → edita label/color/is_open/sort_order/active (la status_key es inmutable).
 *   DELETE → borra un estado personalizado SIN USO.
 *
 * Reglas de los built-ins: no se borran; `pending` no se puede desactivar ni marcar
 * como resuelto (debe seguir abierto y activo). El resto de built-ins sí se pueden
 * relabelar/recolorear/reordenar/desactivar y cambiar su `is_open`.
 */

$admin = Auth::requireAdmin();
$id    = (int) Request::param('id');

$row = DB::run('SELECT * FROM review_statuses WHERE id = ?', [$id])->fetch();
if (!$row) {
    ErrorResponse::send('NOT_FOUND', 'Estado de revisión no encontrado');
}
$isBuiltin = (bool) $row['is_builtin'];
$key       = $row['status_key'];

if (Request::method() === 'PUT') {
    $body = Request::json();
    $set  = [];
    $out  = ['id' => $id, 'key' => $key];

    if (array_key_exists('label', $body)) {
        $label = trim((string) $body['label']);
        if (mb_strlen($label) > 64) {
            ErrorResponse::send('VALIDATION_ERROR', 'El nombre del estado es demasiado largo (máx. 64)');
        }
        // Built-in con label vacío → NULL (usa la traducción i18n por defecto).
        if (!$isBuiltin && $label === '') {
            ErrorResponse::send('VALIDATION_ERROR', 'El nombre del estado es obligatorio');
        }
        $set['label'] = $label === '' ? null : $label;
        $out['label'] = $set['label'];
    }

    if (array_key_exists('color', $body)) {
        $color = (string) $body['color'];
        if (!in_array($color, ReviewStatus::COLORS, true)) {
            ErrorResponse::send('VALIDATION_ERROR', 'Color no válido');
        }
        $set['color'] = $color;
        $out['color'] = $color;
    }

    if (array_key_exists('is_open', $body)) {
        $open = (bool) $body['is_open'];
        if ($key === 'pending' && !$open) {
            ErrorResponse::send('VALIDATION_ERROR', '«Pendiente» debe seguir siendo un estado abierto');
        }
        $set['is_open'] = $open ? 1 : 0;
        $out['is_open'] = $open;
    }

    if (array_key_exists('active', $body)) {
        $active = (bool) $body['active'];
        if ($key === 'pending' && !$active) {
            ErrorResponse::send('VALIDATION_ERROR', '«Pendiente» no se puede desactivar');
        }
        $set['active'] = $active ? 1 : 0;
        $out['active'] = $active;
    }

    if (array_key_exists('sort_order', $body)) {
        $set['sort_order'] = (int) $body['sort_order'];
        $out['sort_order'] = $set['sort_order'];
    }

    if ($set) {
        $cols   = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
        $params = array_values($set);
        $params[] = $id;
        DB::run("UPDATE review_statuses SET $cols WHERE id = ?", $params);
        ReviewStatus::flush();
        Audit::log($admin['id'], 'review_status_update', null, null, ['key' => $key] + $out);
    }
    ErrorResponse::ok($out);
}

if (Request::method() === 'DELETE') {
    if ($isBuiltin) {
        ErrorResponse::send('VALIDATION_ERROR', 'Los estados integrados no se pueden borrar (puedes desactivarlos)');
    }
    $inUse = (int) DB::run(
        'SELECT COUNT(*) AS c FROM submission_reviews WHERE status = ?',
        [$key]
    )->fetch()['c'];
    if ($inUse > 0) {
        ErrorResponse::send('VALIDATION_ERROR', 'El estado está en uso por revisiones existentes; desactívalo en vez de borrarlo', 409);
    }
    DB::run('DELETE FROM review_statuses WHERE id = ?', [$id]);
    ReviewStatus::flush();
    Audit::log($admin['id'], 'review_status_delete', null, null, ['key' => $key]);
    ErrorResponse::ok(['deleted' => true]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
