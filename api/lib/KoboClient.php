<?php
/**
 * Excepción de la API de Kobo que transporta uno de los códigos de error
 * estándar (ver ErrorResponse::CODES).
 */
class KoboException extends RuntimeException {
    public string $errorCode;
    public function __construct(string $errorCode, string $message) {
        $this->errorCode = $errorCode;
        parent::__construct($message);
    }
}

/**
 * Wrapper de la API REST de KoboToolbox (v2).
 * Recibe la URL del servidor y el token YA descifrado.
 */
class KoboClient {
    private const TIMEOUT         = 25;
    private const CONNECT_TIMEOUT = 10;

    private string $serverUrl;
    private string $apiToken;

    public function __construct(string $serverUrl, string $apiToken) {
        $this->serverUrl = rtrim($serverUrl, '/');
        $this->apiToken  = $apiToken;
    }

    /** Lista los formularios (assets de tipo 'survey') de la cuenta. */
    public function getAssets(): array {
        $data    = $this->httpGet('/api/v2/assets/', ['format' => 'json', 'limit' => 1000]);
        $results = $data['results'] ?? [];
        $surveys = array_filter($results, fn($a) => ($a['asset_type'] ?? '') === 'survey');
        return array_values($surveys);
    }

    /**
     * Enlace público de Enketo (formulario rellenable) de un asset desplegado.
     * Vive en el DETALLE del asset (deployment__links), no en el listado.
     * Devuelve la mejor URL disponible o null si no hay.
     */
    public function getEnketoUrl(string $assetUid): ?string {
        $asset = $this->httpGet("/api/v2/assets/$assetUid/", ['format' => 'json']);
        $links = $asset['deployment__links'] ?? [];
        foreach (['url', 'offline_url', 'single_url', 'preview_url', 'iframe_url'] as $k) {
            if (!empty($links[$k])) {
                return $links[$k];
            }
        }
        return null;
    }

    /**
     * Contenido XLSForm de un asset (survey, choices, translations, settings),
     * tal como vive en el DETALLE del asset (`content`). Se usa para cachear el
     * esquema y mostrar etiquetas legibles. Devuelve [] si el asset no trae contenido.
     */
    public function getAssetContent(string $assetUid): array {
        $asset = $this->httpGet("/api/v2/assets/$assetUid/", ['format' => 'json']);
        return $asset['content'] ?? [];
    }

    /** Una página de envíos de un formulario (resultados solamente). */
    public function getSubmissions(string $assetUid, array $query = []): array {
        $query += ['format' => 'json'];
        $data = $this->httpGet("/api/v2/assets/$assetUid/data/", $query);
        return $data['results'] ?? [];
    }

    /**
     * Todos los envíos de un formulario, paginando. Si $sinceIso no es null,
     * pide solo los enviados después de esa fecha (filtro Mongo sobre _submission_time).
     */
    public function getSubmissionsSince(
        string $assetUid,
        ?string $sinceIso = null,
        int $pageSize = 2000,
        int $maxPages = 100
    ): array {
        $base = ['format' => 'json', 'limit' => $pageSize, 'sort' => '{"_submission_time":1}'];
        if ($sinceIso !== null) {
            $base['query'] = json_encode(['_submission_time' => ['$gt' => $sinceIso]]);
        }

        $all   = [];
        $start = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $data    = $this->httpGet("/api/v2/assets/$assetUid/data/", $base + ['start' => $start]);
            $results = $data['results'] ?? [];
            $all     = array_merge($all, $results);

            $count = (int) ($data['count'] ?? 0);
            if (count($results) < $pageSize) break;
            $start += $pageSize;
            if ($start >= $count) break;
        }
        return $all;
    }

    /**
     * Lista ligera de todos los `_id` (numéricos) de los envíos de un formulario,
     * paginando. Pide solo el campo `_id` (`fields=["_id"]`) para que sea barato y
     * sirva de referencia al barrido de bajas (envíos borrados en Kobo).
     */
    public function getAllSubmissionIds(string $assetUid, int $pageSize = 10000, int $maxPages = 100): array {
        $base = ['format' => 'json', 'limit' => $pageSize, 'fields' => '["_id"]', 'sort' => '{"_id":1}'];

        $ids   = [];
        $start = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $data    = $this->httpGet("/api/v2/assets/$assetUid/data/", $base + ['start' => $start]);
            $results = $data['results'] ?? [];
            foreach ($results as $r) {
                if (isset($r['_id'])) $ids[] = (int) $r['_id'];
            }
            $count = (int) ($data['count'] ?? 0);
            if (count($results) < $pageSize) break;
            $start += $pageSize;
            if ($start >= $count) break;
        }
        return $ids;
    }

    /**
     * Edita un envío en Kobo mediante el endpoint de actualización masiva
     * (PATCH /api/v2/assets/{uid}/data/bulk/). $submissionId es el _id numérico
     * de Kobo (no el _uuid). $data mapea nombre de campo (con jerarquía de grupo) → valor.
     * Devuelve true si Kobo aceptó el cambio; lanza KoboException en caso contrario.
     */
    public function editSubmission(string $assetUid, int $submissionId, array $data): bool {
        $payload = [
            'payload' => [
                'submission_ids' => [(string) $submissionId],
                'data'           => $data,
            ],
        ];
        $this->request('PATCH', "/api/v2/assets/$assetUid/data/bulk/", [], $payload);
        return true;
    }

    /**
     * Descarga un adjunto (foto, audio, archivo) de un envío y lo devuelve en
     * memoria como ['body' => bytes, 'mimetype' => tipo]. $url es la `download_url`
     * del adjunto (absoluta) o una ruta relativa al servidor.
     *
     * Seguridad: la primera petición lleva el token, pero las redirecciones a un
     * almacenamiento externo (p. ej. S3 con URL firmada) se siguen MANUALMENTE y
     * SIN el token, para no filtrar la credencial a otro dominio.
     */
    public function getAttachment(string $url): array {
        if (!preg_match('#^https?://#', $url)) {
            $url = $this->serverUrl . '/' . ltrim($url, '/');
        }

        // 1) Petición autenticada, sin seguir redirecciones.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Authorization: Token ' . $this->apiToken],
        ]);
        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype    = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        if ($errno !== 0) {
            throw new KoboException('KOBO_TIMEOUT', 'No se pudo descargar el adjunto');
        }

        // 2) Redirección a almacenamiento firmado: seguirla sin el token.
        if (in_array($status, [301, 302, 303, 307, 308], true) && $redirect !== '') {
            // Defensa anti-SSRF: solo seguir redirecciones a HTTP(S) (no file://,
            // gopher://, etc.) y con un tope de saltos.
            if (!preg_match('#^https?://#i', $redirect)) {
                throw new KoboException('KOBO_TIMEOUT', 'No se pudo descargar el adjunto');
            }
            $ch2 = curl_init($redirect);
            $opts2 = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            ];
            if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
                $opts2[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
            }
            curl_setopt_array($ch2, $opts2);
            $body   = curl_exec($ch2);
            $errno  = curl_errno($ch2);
            $status = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $ctype  = (string) curl_getinfo($ch2, CURLINFO_CONTENT_TYPE);
            if ($errno !== 0) {
                throw new KoboException('KOBO_TIMEOUT', 'No se pudo descargar el adjunto');
            }
        }

        if ($status === 401 || $status === 403) {
            throw new KoboException('KOBO_UNAUTHORIZED', 'Token de Kobo expirado o inválido');
        }
        if ($status === 404) {
            throw new KoboException('KOBO_FORM_NOT_FOUND', 'Adjunto no encontrado en Kobo');
        }
        if ($status >= 400) {
            throw new KoboException('KOBO_TIMEOUT', "Kobo respondió con estado $status");
        }

        return ['body' => (string) $body, 'mimetype' => $ctype ?: 'application/octet-stream'];
    }

    // ---------- HTTP ----------

    private function httpGet(string $path, array $query = []): array {
        return $this->request('GET', $path, $query);
    }

    /** Petición HTTP genérica a Kobo, con manejo de errores → KoboException. */
    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): array {
        $url = $this->serverUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Token ' . $this->apiToken,
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        ]);
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new KoboException('KOBO_TIMEOUT', 'Timeout al contactar con el servidor de Kobo');
        }
        if ($errno !== 0) {
            throw new KoboException('KOBO_TIMEOUT', "No se pudo contactar con Kobo: $errmsg");
        }
        if ($status === 401 || $status === 403) {
            throw new KoboException('KOBO_UNAUTHORIZED', 'Token de Kobo expirado o inválido');
        }
        if ($status === 404) {
            throw new KoboException('KOBO_FORM_NOT_FOUND', 'Recurso no encontrado en Kobo');
        }
        if ($status === 429) {
            throw new KoboException('KOBO_RATE_LIMIT', 'Se alcanzó el límite de peticiones de Kobo');
        }
        if ($status >= 400) {
            throw new KoboException('KOBO_TIMEOUT', "Kobo respondió con estado $status");
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            // Algunas respuestas válidas (ej. 204) pueden venir vacías.
            return [];
        }
        return $json;
    }
}
