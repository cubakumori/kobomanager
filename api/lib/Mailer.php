<?php
/**
 * Envío de email a través de la API REST de Resend (https://api.resend.com/emails).
 * Sin SDK ni dependencias: una simple petición cURL, coherente con el resto del backend.
 *
 * Si RESEND_API_KEY está vacío, no envía y devuelve false (útil en desarrollo).
 */
class Mailer {
    public static function send(string $to, string $subject, string $html, ?string $text = null): bool {
        if (RESEND_API_KEY === '') {
            error_log("Mailer: RESEND_API_KEY no configurado; email a $to no enviado.");
            return false;
        }

        $payload = [
            'from'    => MAIL_FROM,
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ];
        if ($text !== null) {
            $payload['text'] = $text;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . RESEND_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $netErr       = curl_errno($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($netErr !== 0) {
            error_log("Mailer: error de red al enviar a $to: " . curl_error($ch));
            return false;
        }
        if ($status < 200 || $status >= 300) {
            error_log("Mailer: Resend respondió $status al enviar a $to: $responseBody");
            return false;
        }
        return true;
    }
}
