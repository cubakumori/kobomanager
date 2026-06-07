<?php
/**
 * Normalización de los adjuntos (`_attachments`) de un envío cacheado.
 *
 * Reutilizada por el detalle autenticado (`submissions/item.php`) y por el
 * detalle público de un enlace compartido (`public/share_submission.php`), para
 * que ambos describan los adjuntos igual y la UI pueda agruparlos por tipo.
 *
 * El archivo en sí nunca se sirve desde aquí: se descarga por un proxy del
 * backend (autenticado o público) que usa el token de la cuenta Kobo; el
 * navegador solo recibe el `uid` del adjunto.
 */
class Attachments {

    /**
     * Lista normalizada de adjuntos de un payload de envío. Cada elemento:
     *   uid, name, mimetype, field (question_xpath), kind.
     *
     * `kind` clasifica para la galería agrupada por tipo:
     *   image | audio | video | document (PDF/ofimática) | file (otros).
     */
    public static function forPayload(array $payload): array {
        $out = [];
        foreach (($payload['_attachments'] ?? []) as $a) {
            $uid = $a['uid'] ?? null;
            if (!$uid) continue;
            $mime = (string) ($a['mimetype'] ?? '');
            $out[] = [
                'uid'      => $uid,
                'name'     => $a['media_file_basename'] ?? basename((string) ($a['filename'] ?? $uid)),
                'mimetype' => $mime ?: null,
                'field'    => $a['question_xpath'] ?? null,
                'kind'     => self::kind($mime),
            ];
        }
        return $out;
    }

    /** Clasifica un mimetype en uno de los cinco grupos de la galería. */
    public static function kind(string $mime): string {
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (self::isDocument($mime)) return 'document';
        return 'file';
    }

    /** Documentos «ofimáticos» (PDF, Office, OpenDocument, texto plano/CSV). */
    private static function isDocument(string $mime): bool {
        if ($mime === '') return false;
        static $exact = [
            'application/pdf',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/rtf',
            'application/csv',
        ];
        if (in_array($mime, $exact, true)) return true;
        if (str_starts_with($mime, 'text/')) return true;                              // text/plain, text/csv…
        if (str_starts_with($mime, 'application/vnd.openxmlformats-officedocument')) return true; // docx/xlsx/pptx
        if (str_starts_with($mime, 'application/vnd.oasis.opendocument')) return true; // odt/ods/odp
        return false;
    }
}
