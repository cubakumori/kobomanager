<?php
/**
 * Excepción de la API de Kobo que transporta uno de los códigos de error
 * estándar (ver ErrorResponse::CODES).
 */
class KoboException extends RuntimeException {
    public string $errorCode;
    /** Código HTTP de Kobo que originó el error (si lo hubo), para afinar el diagnóstico. */
    public ?int $httpStatus;
    public function __construct(string $errorCode, string $message, ?int $httpStatus = null) {
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
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
     *
     * Devuelve un GENERADOR que entrega los envíos página a página (array de filas),
     * para que el llamador procese/persista cada página sin acumular todo el
     * histórico en memoria (el primer sync de un formulario grande es justo el
     * caso con más filas). Si se alcanza el tope de páginas sin agotar los
     * resultados se lanza KoboException: un histórico truncado NO es un éxito
     * (el barrido de bajas interpretaría lo no traído como envíos borrados).
     *
     * FIN DE LISTA: lo dice la API (`next` = null), NUNCA «llegaron menos filas
     * que el limit pedido» — el servidor de Kobo TOPA la página por debajo del
     * limit (verificado: pide 10000 y responde 1000 con `next`), y esa heurística
     * cortaba tras la primera página sin disparar el guard de truncado (la caché
     * quedaba clavada en el tope y el barrido de bajas «retiraba» lo no traído).
     * El avance usa lo realmente recibido, no el limit. Mismo contrato en
     * getAllSubmissionIds y getValidationStatuses.
     *
     * @return \Generator<array[]>
     */
    public function getSubmissionsSince(
        string $assetUid,
        ?string $sinceIso = null,
        int $pageSize = 2000,
        int $maxPages = 100
    ): \Generator {
        $base = ['format' => 'json', 'limit' => $pageSize, 'sort' => '{"_submission_time":1}'];
        if ($sinceIso !== null) {
            $base['query'] = json_encode(['_submission_time' => ['$gt' => $sinceIso]]);
        }

        $start = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $data    = $this->httpGet("/api/v2/assets/$assetUid/data/", $base + ['start' => $start]);
            $results = $data['results'] ?? [];
            if ($results) {
                yield $results;
            }

            // Última página según la API; la página vacía es el cinturón anti-bucle
            // por si una respuesta anómala trajera `next` sin resultados.
            if (!$results || ($data['next'] ?? null) === null) return;
            $start += count($results);
        }
        throw new KoboException(
            'KOBO_BAD_RESPONSE',
            "El formulario excede el tope de paginación ($maxPages páginas); sincronización incompleta abortada"
        );
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
            // Fin de lista = `next` null (el servidor puede topar la página por debajo
            // del limit pedido; ver getSubmissionsSince). Avance por lo recibido.
            if (!$results || ($data['next'] ?? null) === null) return $ids;
            $start += count($results);
        }
        // Lista truncada = referencia inválida para el barrido de bajas: abortar.
        throw new KoboException(
            'KOBO_BAD_RESPONSE',
            "El formulario excede el tope de paginación del barrido de bajas ($maxPages páginas)"
        );
    }

    /**
     * Edita un envío en Kobo mediante el endpoint de actualización masiva
     * (PATCH /api/v2/assets/{uid}/data/bulk/). $submissionId es el _id numérico
     * de Kobo (no el _uuid). $data mapea nombre de campo (con jerarquía de grupo) → valor.
     *
     * IMPORTANTE (verificado contra Kobo real): una edición crea una NUEVA versión
     * del envío con un `_uuid` NUEVO (el original queda como `root_uuid`); el `_id`
     * numérico se conserva. Devuelve el `_uuid` resultante para que el llamador
     * re-sincronice su caché (la clave de caché/revisiones es el _uuid).
     *
     * El endpoint bulk responde HTTP 200 AUNQUE la edición por-envío falle: el
     * detalle viaja en el cuerpo (`failures` / `results[].status_code`). Por eso no
     * basta con el código HTTP; se inspecciona el cuerpo y se lanza KoboException
     * si la edición no fue aceptada.
     */
    public function editSubmission(string $assetUid, int $submissionId, array $data): string {
        $payload = [
            'payload' => [
                'submission_ids' => [(string) $submissionId],
                'data'           => $data,
            ],
        ];
        $resp = $this->request('PATCH', "/api/v2/assets/$assetUid/data/bulk/", [], $payload);

        $failures = (int) ($resp['failures'] ?? 0);
        $result   = $resp['results'][0] ?? null;
        $code     = (int) ($result['status_code'] ?? 0);
        if ($failures > 0 || ($result !== null && $code >= 400)) {
            $msg = is_string($result['message'] ?? null) ? $result['message'] : 'Kobo rechazó la edición del envío';
            throw new KoboException('KOBO_EDIT_FAILED', $msg);
        }

        // `uuid` del resultado = nuevo _uuid de la versión editada (vacío si Kobo no lo devolvió).
        return (string) ($result['uuid'] ?? '');
    }

    /**
     * Fija (o limpia) el `_validation_status` nativo de uno o varios envíos.
     * $submissionIds = _id numéricos de Kobo; $statusUid = uid de validación
     * (validation_status_approved | _not_approved | _on_hold) o '' para limpiarlo
     * (equivale a «sin estado» / pending). Lanza KoboException si Kobo lo rechaza
     * (push bloqueante, espejo de editSubmission).
     *
     * - Fijar: PATCH /api/v2/assets/{uid}/data/validation_statuses/ con el envoltorio
     *   {payload:{submission_ids, "validation_status.uid"}} (mismo formato que el bulk).
     * - Limpiar: DELETE /api/v2/assets/{uid}/data/{id}/validation_status/ por envío
     *   (vía inequívoca para «sin estado»; el lote a pending es poco frecuente).
     */
    public function setValidationStatuses(string $assetUid, array $submissionIds, string $statusUid): void {
        $ids = array_values(array_map('intval', $submissionIds));
        if (!$ids) return;

        try {
            if ($statusUid === '') {
                foreach ($ids as $id) {
                    $this->request('DELETE', "/api/v2/assets/$assetUid/data/$id/validation_status/");
                }
                return;
            }

            $payload = [
                'payload' => [
                    'submission_ids'        => $ids,
                    'validation_status.uid' => $statusUid,
                ],
            ];
            $resp = $this->request('PATCH', "/api/v2/assets/$assetUid/data/validation_statuses/", [], $payload);

            // El endpoint puede responder 200 con un detalle de fallos parciales (como el
            // bulk de edición). Si lo trae, lo tratamos como error.
            if ((int) ($resp['failures'] ?? 0) > 0) {
                throw new KoboException('KOBO_EDIT_FAILED', 'Kobo rechazó el cambio de estado de validación');
            }
        } catch (KoboException $e) {
            // Un 403 aquí casi siempre significa que la cuenta NO tiene el permiso
            // «Validate Submissions» sobre el formulario (el token es válido: leer/editar
            // funcionan). Se traduce a un código propio para no confundir con «token inválido».
            if ($e->httpStatus === 403) {
                throw new KoboException(
                    'KOBO_VALIDATE_FORBIDDEN',
                    'La cuenta de Kobo no tiene permiso para validar envíos en este formulario'
                );
            }
            throw $e;
        }
    }

    /**
     * Mapa _uuid => `validation_status.uid` de TODOS los envíos de un formulario.
     * Pide solo `_uuid`, `_id` y `_validation_status` (`fields=[...]`) para que sea
     * barato; sirve al pull del estado de validación en cada sync (incremental no
     * re-trae envíos viejos cuyo estado cambió en Kobo). Gemelo de getAllSubmissionIds.
     * Un envío sin estado se devuelve con uid ''.
     */
    public function getValidationStatuses(string $assetUid, int $pageSize = 10000, int $maxPages = 100): array {
        $base = [
            'format' => 'json',
            'limit'  => $pageSize,
            'fields' => '["_uuid","_id","_validation_status"]',
            'sort'   => '{"_id":1}',
        ];

        $map   = [];
        $start = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $data    = $this->httpGet("/api/v2/assets/$assetUid/data/", $base + ['start' => $start]);
            $results = $data['results'] ?? [];
            foreach ($results as $r) {
                $uid = $r['_uuid'] ?? (isset($r['_id']) ? (string) $r['_id'] : null);
                if ($uid === null) continue;
                $map[$uid] = ValidationStatus::uidFromPayload($r);
            }
            // Fin de lista = `next` null (el servidor puede topar la página por debajo
            // del limit pedido; ver getSubmissionsSince). Avance por lo recibido.
            if (!$results || ($data['next'] ?? null) === null) return $map;
            $start += count($results);
        }
        // Mapa truncado: reconciliar validación con una foto parcial insertaría
        // revisiones sintéticas erróneas para lo no traído en syncs futuros.
        throw new KoboException(
            'KOBO_BAD_RESPONSE',
            "El formulario excede el tope de paginación del pull de validación ($maxPages páginas)"
        );
    }

    /**
     * Descarga un adjunto (foto, audio, archivo) de un envío y lo STREAMEA a la
     * salida (php://output) según llega, sin cargarlo entero en memoria (un vídeo
     * de encuesta puede pesar cientos de MB). $url es la `download_url` del
     * adjunto (absoluta) o una ruta relativa al servidor.
     *
     * $onHeaders(string $mimetype, ?int $length) se invoca UNA vez, justo antes
     * del primer byte del cuerpo: ahí el llamador emite sus cabeceras HTTP. Los
     * errores (4xx/5xx, timeout de conexión) se lanzan como KoboException ANTES
     * de llamar a $onHeaders — nunca con la respuesta ya empezada. Un fallo de
     * red a mitad de descarga solo puede truncar el cuerpo (inevitable).
     *
     * Seguridad: la primera petición lleva el token, pero las redirecciones a un
     * almacenamiento externo (p. ej. S3 con URL firmada) se siguen MANUALMENTE y
     * SIN el token, para no filtrar la credencial a otro dominio.
     */
    public function getAttachment(string $url, callable $onHeaders): void {
        if (!preg_match('#^https?://#', $url)) {
            $url = $this->serverUrl . '/' . ltrim($url, '/');
        }

        // 1) Petición autenticada, sin seguir redirecciones.
        $r = $this->streamLeg($url, ['Authorization: Token ' . $this->apiToken], false, $onHeaders);
        if ($r['errno'] !== 0) {
            if ($r['started']) return; // truncado a mitad: no se puede deshacer
            throw new KoboException('KOBO_TIMEOUT', 'No se pudo descargar el adjunto');
        }

        // 2) Redirección a almacenamiento firmado: seguirla sin el token.
        if (in_array($r['status'], [301, 302, 303, 307, 308], true) && $r['redirect'] !== '') {
            // Defensa anti-SSRF: solo seguir redirecciones a HTTP(S) (no file://,
            // gopher://, etc.) y con un tope de saltos.
            if (!preg_match('#^https?://#i', $r['redirect'])) {
                throw new KoboException('KOBO_TIMEOUT', 'No se pudo descargar el adjunto');
            }
            $r = $this->streamLeg($r['redirect'], [], true, $onHeaders);
            if ($r['errno'] !== 0) {
                if ($r['started']) return;
                throw new KoboException('KOBO_TIMEOUT', 'No se pudo descargar el adjunto');
            }
        }

        if ($r['status'] === 401 || $r['status'] === 403) {
            throw new KoboException('KOBO_UNAUTHORIZED', 'Token de Kobo expirado o inválido');
        }
        if ($r['status'] === 404) {
            throw new KoboException('KOBO_FORM_NOT_FOUND', 'Adjunto no encontrado en Kobo');
        }
        if ($r['status'] >= 400 || $r['status'] < 200) {
            throw new KoboException('KOBO_TIMEOUT', "Kobo respondió con estado {$r['status']}");
        }

        // 2xx con cuerpo VACÍO: el write callback nunca corrió → emitir cabeceras aquí.
        if (!$r['started']) {
            $onHeaders($r['ctype'] !== '' ? $r['ctype'] : 'application/octet-stream', 0);
        }
    }

    /**
     * Un tramo cURL del proxy de adjuntos: streamea los cuerpos 2xx a la salida
     * (llamando a $onHeaders antes del primer byte) y DESCARTA los cuerpos de
     * redirecciones/errores (sus mensajes se generan aparte). Devuelve
     * ['status', 'redirect', 'ctype', 'started', 'errno'].
     */
    private function streamLeg(string $url, array $headers, bool $follow, callable $onHeaders): array {
        $started = false;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$started, $onHeaders): int {
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($status < 200 || $status >= 300) {
                    return strlen($chunk); // redirect/error: descartar el cuerpo
                }
                if (!$started) {
                    $started = true;
                    $len = (float) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                    $onHeaders(
                        (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                        $len > 0 ? (int) $len : null
                    );
                }
                echo $chunk;
                flush();
                return strlen($chunk);
            },
        ];
        if ($follow) {
            $opts[CURLOPT_MAXREDIRS] = 3;
            if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
                $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
            }
        }
        if ($headers) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        return [
            'status'   => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'redirect' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
            'ctype'    => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'started'  => $started,
            'errno'    => curl_errno($ch),
        ];
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
            // 401 = token inválido/expirado; 403 = el token es válido pero la cuenta no
            // tiene permiso para esta operación sobre el asset. Se conserva el status para
            // que el llamador pueda dar un mensaje más preciso (p. ej. la validación).
            throw new KoboException('KOBO_UNAUTHORIZED', 'Token de Kobo expirado o inválido', $status);
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

        // Cuerpo vacío = respuesta válida sin contenido (ej. 204 de un DELETE).
        if (trim((string) $body) === '') {
            return [];
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            // Un 2xx con cuerpo no-JSON (página HTML de un proxy intermedio, portal
            // cautivo…) NO puede tratarse como «cero resultados»: el barrido de bajas
            // interpretaría la lista vacía como un borrado masivo en Kobo.
            throw new KoboException('KOBO_BAD_RESPONSE', 'Kobo devolvió una respuesta que no es JSON', $status);
        }
        return $json;
    }
}
