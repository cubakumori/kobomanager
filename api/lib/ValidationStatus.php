<?php
/**
 * Mapeo entre el estado de revisión interno de KoboManager y el campo nativo
 * `_validation_status` de KoboToolbox.
 *
 * KoboManager guarda un log de revisiones (submission_reviews.status) con cuatro
 * estados; Kobo tiene un único campo `_validation_status` cuyo `uid` toma valores
 * fijos. Esta lib centraliza la correspondencia para el push (al revisar) y el
 * pull (en cada sync). Ver lib/SubmissionSync::reconcileValidation y los endpoints
 * v1/submissions/review.php y v1/forms/review_batch.php.
 *
 *   pending  ⇄ (vacío / sin estado)
 *   approved ⇄ validation_status_approved
 *   rejected ⇄ validation_status_not_approved
 *   on_hold  ⇄ validation_status_on_hold
 */
class ValidationStatus {
    /** estado interno => uid nativo de Kobo ('' = sin estado / pending). */
    private const TO_KOBO = [
        'pending'  => '',
        'approved' => 'validation_status_approved',
        'rejected' => 'validation_status_not_approved',
        'on_hold'  => 'validation_status_on_hold',
    ];

    /** uid nativo de Kobo => estado interno. */
    private const FROM_KOBO = [
        'validation_status_approved'     => 'approved',
        'validation_status_not_approved' => 'rejected',
        'validation_status_on_hold'      => 'on_hold',
    ];

    /**
     * Estado interno → `validation_status.uid` de Kobo. Devuelve '' para 'pending'
     * (que en Kobo equivale a «sin estado de validación») y para estados no mapeados.
     */
    public static function toKobo(string $status): string {
        return self::TO_KOBO[$status] ?? '';
    }

    /**
     * `validation_status.uid` de Kobo → estado interno. NULL, '' o un uid
     * desconocido se interpretan como 'pending' (sin estado).
     */
    public static function fromKobo(?string $uid): string {
        if ($uid === null || $uid === '') {
            return 'pending';
        }
        return self::FROM_KOBO[$uid] ?? 'pending';
    }

    /**
     * Extrae el `validation_status.uid` del payload de un envío de Kobo. El campo
     * `_validation_status` viene como objeto {uid,label,...} o vacío. Devuelve '' si
     * no hay estado.
     */
    public static function uidFromPayload(array $payload): string {
        $vs = $payload['_validation_status'] ?? null;
        if (is_array($vs) && isset($vs['uid']) && is_string($vs['uid'])) {
            return $vs['uid'];
        }
        return '';
    }

    /** Estados internos válidos del flujo de revisión. */
    public const STATUSES = ['pending', 'approved', 'on_hold', 'rejected'];

    /**
     * Registra una revisión: inserta la fila en `submission_reviews` (el log) Y
     * actualiza el estado vigente desnormalizado `submissions_cache.review_status`.
     * ÚNICA vía de escritura de revisiones (endpoints de revisión, pull de
     * validación del sync y tests): mantiene el log y la columna siempre de acuerdo.
     * Devuelve el id de la revisión insertada.
     *
     * `$formId` desambigua el uid (único solo POR formulario desde 1.52.0): los
     * llamadores de producción lo pasan siempre; si falta (tests, seeds) se
     * resuelve desde la caché, que es unívoco mientras el uid no esté duplicado.
     */
    public static function recordReview(string $submissionUid, ?int $userId, string $source, string $status, ?string $comment = null, ?int $formId = null): int {
        if ($formId === null) {
            $row    = DB::run('SELECT form_id FROM submissions_cache WHERE submission_uid = ? LIMIT 1', [$submissionUid])->fetch();
            $formId = $row ? (int) $row['form_id'] : null;
        }
        DB::run(
            'INSERT INTO submission_reviews (form_id, submission_uid, user_id, source, status, comment) VALUES (?, ?, ?, ?, ?, ?)',
            [$formId, $submissionUid, $userId, $source, $status, $comment]
        );
        $id = (int) DB::conn()->lastInsertId();
        if ($formId !== null) {
            DB::run(
                'UPDATE submissions_cache SET review_status = ? WHERE form_id = ? AND submission_uid = ?',
                [$status, $formId, $submissionUid]
            );
        } else {
            DB::run(
                'UPDATE submissions_cache SET review_status = ? WHERE submission_uid = ?',
                [$status, $submissionUid]
            );
        }
        return $id;
    }

    /**
     * Fragmento SQL (+ params) que restringe envíos por su estado de revisión
     * VIGENTE, sobre la columna desnormalizada `submissions_cache.review_status`
     * (un envío sin revisión vale 'pending' por DEFAULT). `$colExpr` = expresión
     * de la columna en la consulta que lo usa (p. ej. 'sc.review_status').
     * Estado null o no reconocido → ['1=1', []] (sin restricción). Fuente única
     * usada por lib/Stats y por los endpoints públicos de enlaces.
     *
     * @return array{0:string,1:array}
     */
    public static function statusFilterSql(?string $status, string $colExpr = 'review_status'): array {
        if (!in_array($status, self::STATUSES, true)) {
            return ['1=1', []];
        }
        return ["$colExpr = ?", [$status]];
    }
}
