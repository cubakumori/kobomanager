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

    /**
     * ¿Puede servirse INLINE (mostrarse en el navegador) en los proxies de adjuntos?
     * Solo multimedia (imagen/audio/vídeo) y NUNCA `image/svg+xml`: un SVG es un
     * documento XML que puede llevar <script>; aunque la CSP con `sandbox` del proxy
     * ya lo neutraliza, forzar la descarga es defensa en profundidad gratis (la
     * galería lo sigue clasificando como imagen para agruparlo).
     */
    public static function inlineSafe(string $mime): bool {
        $m = strtolower(trim(explode(';', $mime)[0]));
        if ($m === 'image/svg+xml' || str_ends_with($m, '+xml')) return false;
        return in_array(self::kind($m), ['image', 'audio', 'video'], true);
    }

    /**
     * Valor de la cabecera `Content-Disposition` para una descarga/inline con nombre
     * de archivo arbitrario: `filename="…"` con un respaldo ASCII (para clientes
     * antiguos) y `filename*=UTF-8''…` (RFC 6266/8187) con el nombre real
     * percent-encoded — sin él, «Informe_ñandú.xlsx» o «foto niño.jpg» llegaban
     * mutilados en varios navegadores. Elimina comillas y saltos de línea.
     */
    public static function contentDisposition(string $type, string $filename): string {
        $name = str_replace(["\r", "\n", '"', '\\'], '', trim($filename));
        if ($name === '') $name = 'file';
        // Respaldo ASCII: transliteración básica + «?» para lo que no quepa.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii === false || trim($ascii) === '') {
            $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'file';
        }
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $ascii);
        $header = $type . '; filename="' . $ascii . '"';
        if ($ascii !== $name) {
            $header .= "; filename*=UTF-8''" . rawurlencode($name);
        }
        return $header;
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
