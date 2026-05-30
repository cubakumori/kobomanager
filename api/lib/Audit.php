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
}
