<?php
/**
 * /api/v1/admin/forms/{id}/member-aliases   (solo admin)
 *
 *   GET → { items: [{axis, from, to}] } — la tabla de alias del formulario
 *         (from = clave normalizada guardada; to = grafía canónica).
 *   PUT { items: [{axis: 'member'|'team', from: string, to: string}] }
 *       → REEMPLAZO COMPLETO de la tabla (patrón del plan de muestra): DELETE +
 *         re-INSERT en una transacción. `from` admite cualquier grafía (se guarda
 *         su clave normalizada); entradas duplicadas por (axis, clave) se quedan
 *         con la última; tope de 500 por formulario.
 *
 * Los alias son la capa 3 de la normalización de miembro/equipo (lib/MemberNorm):
 * re-mapean variantes que el plegado no une («jlvh» → «JLHV»). Solo afectan a la
 * agrupación de las vistas cuando forms.member_normalize = 'alias'; guardarlos
 * con otro modo activo es válido (quedan latentes).
 */

$user   = Auth::requireAdmin();
$formId = (int) Request::param('id');
$method = Request::method();

$form = DB::run('SELECT id FROM forms WHERE id = ?', [$formId])->fetch();
if (!$form) {
    ErrorResponse::send('NOT_FOUND', 'Formulario no encontrado');
}

if ($method === 'GET') {
    $items = array_map(fn($r) => [
        'axis' => $r['axis'],
        'from' => $r['from_key'],
        'to'   => $r['to_value'],
    ], DB::run(
        'SELECT axis, from_key, to_value FROM member_aliases WHERE form_id = ? ORDER BY axis, to_value, from_key',
        [$formId]
    )->fetchAll());
    ErrorResponse::ok(['items' => $items]);
}

if ($method === 'PUT') {
    $body  = Request::json();
    $items = $body['items'] ?? null;
    if (!is_array($items)) {
        ErrorResponse::send('VALIDATION_ERROR', 'Falta la lista de alias (items)');
    }
    if (count($items) > 500) {
        ErrorResponse::send('VALIDATION_ERROR', 'Demasiados alias (máximo 500 por formulario)');
    }

    // Normalizar y deduplicar: clave = (eje, clave plegada de la variante).
    $clean = [];
    foreach ($items as $it) {
        $axis = (string) ($it['axis'] ?? '');
        $from = trim((string) ($it['from'] ?? ''));
        $to   = trim((string) ($it['to'] ?? ''));
        if (!in_array($axis, MemberNorm::AXES, true)) {
            ErrorResponse::send('VALIDATION_ERROR', "Eje de alias no válido: $axis");
        }
        if ($from === '' || $to === '') {
            ErrorResponse::send('VALIDATION_ERROR', 'Cada alias necesita variante y canónico');
        }
        if (mb_strlen($from) > 255 || mb_strlen($to) > 255) {
            ErrorResponse::send('VALIDATION_ERROR', 'Alias demasiado largo (máximo 255 caracteres)');
        }
        $fromKey = MemberNorm::normKey($from);
        $clean[$axis . '|' . $fromKey] = ['axis' => $axis, 'from_key' => $fromKey, 'to' => $to];
    }

    $pdo = DB::conn();
    $pdo->beginTransaction();
    try {
        DB::run('DELETE FROM member_aliases WHERE form_id = ?', [$formId]);
        $stmt = $pdo->prepare(
            'INSERT INTO member_aliases (form_id, axis, from_key, to_value) VALUES (?, ?, ?, ?)'
        );
        foreach ($clean as $a) {
            $stmt->execute([$formId, $a['axis'], $a['from_key'], $a['to']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    Audit::log($user['id'], 'update_member_aliases', $formId, null, ['count' => count($clean)]);
    ErrorResponse::ok(['count' => count($clean)]);
}

ErrorResponse::send('VALIDATION_ERROR', 'Método no permitido', 405);
