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
     * Fragmento SQL (+ params) que restringe envíos por el estado de su revisión MÁS
     * RECIENTE, equivalente a `COALESCE(latest.status,'pending') = $status` (un envío
     * sin revisión cuenta como 'pending'). `$uidExpr` = expresión del `submission_uid`
     * en la consulta que lo usa (p. ej. 'sc.submission_uid' o 'submission_uid').
     * Estado null o no reconocido → ['1=1', []] (sin restricción). Fuente única usada
     * por lib/Stats y por los endpoints públicos de enlaces.
     *
     * @return array{0:string,1:array}
     */
    public static function latestFilterSql(?string $status, string $uidExpr = 'submission_uid'): array {
        if (!in_array($status, self::STATUSES, true)) {
            return ['1=1', []];
        }
        $latest = "submission_reviews r
                   JOIN (SELECT submission_uid, MAX(id) AS max_id FROM submission_reviews GROUP BY submission_uid) m
                     ON m.max_id = r.id";
        if ($status === 'pending') {
            // pendiente = sin revisión O última revisión 'pending'.
            return ["$uidExpr NOT IN (SELECT r.submission_uid FROM $latest WHERE r.status IN ('approved','on_hold','rejected'))", []];
        }
        return ["$uidExpr IN (SELECT r.submission_uid FROM $latest WHERE r.status = ?)", [$status]];
    }
}
