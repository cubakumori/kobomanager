<?php
/**
 * Registro de auditoría (tabla audit_log).
 */
class Audit {
    public static function log(
        int $userId,
        string $action,
        ?int $formId = null,
        ?string $submissionUid = null,
        ?array $detail = null
    ): void {
        DB::run(
            'INSERT INTO audit_log (user_id, form_id, submission_uid, action, detail)
             VALUES (?, ?, ?, ?, ?)',
            [
                $userId, $formId, $submissionUid, $action,
                $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }

    /**
     * Consulta paginada y filtrada del registro de auditoría. Lógica compartida
     * por el visor admin (`admin/audit.php`) y la auditoría propia (`audit/me.php`).
     *
     * $filters admite: page, per_page, action, user_id, form_id, date_from,
     * date_to (YYYY-MM-DD), search. Un `user_id` truthy fuerza el filtro por ese
     * usuario (en `audit/me.php` se inyecta el usuario actual).
     *
     * Si $scopeActionsToUser es true y hay user_id, la lista de acciones para el
     * desplegable se limita a las del propio usuario (no se filtra el vocabulario
     * global). El visor admin lo deja en false para conservar su comportamiento.
     *
     * Devuelve ['items', 'page', 'per_page', 'total', 'actions'].
     */
    public static function query(array $filters, bool $scopeActionsToUser = false): array {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $where[]  = 'a.action = ?';
            $params[] = $action;
        }
        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId) {
            $where[]  = 'a.user_id = ?';
            $params[] = $userId;
        }
        $formId = (int) ($filters['form_id'] ?? 0);
        if ($formId) {
            $where[]  = 'a.form_id = ?';
            $params[] = $formId;
        }
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '' && ($ts = strtotime($dateFrom)) !== false) {
            $where[]  = 'a.created_at >= ?';
            $params[] = date('Y-m-d 00:00:00', $ts);
        }
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '' && ($ts = strtotime($dateTo)) !== false) {
            $where[]  = 'a.created_at < ?';
            $params[] = date('Y-m-d 00:00:00', strtotime('+1 day', $ts));
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[]  = '(a.submission_uid LIKE ? OR CAST(a.detail AS CHAR) LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) DB::run("SELECT COUNT(*) AS c FROM audit_log a $whereSql", $params)->fetch()['c'];

        $rows = DB::run(
            "SELECT a.id, a.user_id, a.form_id, a.submission_uid, a.action, a.detail, a.created_at,
                    u.name AS user_name, u.email AS user_email, f.name AS form_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN forms f ON f.id = a.form_id
             $whereSql
             ORDER BY a.id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        )->fetchAll();

        $items = array_map(fn($r) => [
            'id'             => (int) $r['id'],
            'created_at'     => $r['created_at'],
            'action'         => $r['action'],
            'user_id'        => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'user_name'      => $r['user_name'],
            'user_email'     => $r['user_email'],
            'form_id'        => $r['form_id'] !== null ? (int) $r['form_id'] : null,
            'form_name'      => $r['form_name'],
            'submission_uid' => $r['submission_uid'],
            'detail'         => $r['detail'] !== null ? json_decode($r['detail'], true) : null,
        ], $rows);

        // Acciones existentes (para el desplegable de filtro). Cuando la vista es
        // propia, se limita a las acciones del propio usuario.
        if ($scopeActionsToUser && $userId) {
            $actions = array_map(
                fn($r) => $r['action'],
                DB::run('SELECT DISTINCT action FROM audit_log WHERE user_id = ? ORDER BY action', [$userId])->fetchAll()
            );
        } else {
            $actions = array_map(
                fn($r) => $r['action'],
                DB::run('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll()
            );
        }

        return [
            'items'    => $items,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'actions'  => $actions,
        ];
    }
}
