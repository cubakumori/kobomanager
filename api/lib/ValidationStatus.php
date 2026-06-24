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
}
