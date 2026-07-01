<?php
/**
 * Enlaces de solo lectura compartibles (tabla `share_links`).
 *
 * Un enlace expone una vista de solo lectura de un formulario (lista / detalle /
 * mapa) sin sesión en KoboManager ni cuenta Kobo. El acceso es por un token
 * impredecible en la URL; opcionalmente protegido con contraseña.
 *
 * El subconjunto de envíos visibles se restringe con el mismo motor de scoping
 * por filas que los permisos de viewer (`RowScope`), guardado en `row_filter`.
 *
 * Para enlaces con contraseña se emite un "ticket" firmado (HMAC con JWT_SECRET)
 * de vida corta que el visitante adjunta en las peticiones de datos, evitando
 * reenviar la contraseña en cada llamada.
 */
class ShareLink {

    /** Vida del ticket de acceso para enlaces con contraseña (segundos). */
    private const TICKET_TTL = 3600;

    /** Rate-limit de las peticiones públicas por IP (anti-scraping/DoS). Generoso:
     *  un visitante real carga lista + varios detalles + adjuntos de una galería. */
    private const RATE_MAX    = 240;
    private const RATE_WINDOW = 60;

    /**
     * Throttle por IP de los endpoints públicos de share. Los enlaces se apoyan en
     * un token impredecible + revocación/caducidad; esto añade un tope por IP para
     * frenar el scraping/abuso de un enlace filtrado. Corta con 429 si se excede.
     */
    public static function throttle(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (RateLimit::tooManyBucket($ip, 'share', self::RATE_MAX, self::RATE_WINDOW)) {
            ErrorResponse::send('AUTH_RATE_LIMITED');
        }
        RateLimit::hitBucket($ip, 'share');
    }

    /** Genera un token de enlace impredecible (URL-safe). */
    public static function generateToken(): string {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    /**
     * Valida y resuelve los ajustes COMUNES de un enlace (los que comparten la
     * creación simple y la creación en LOTE) a partir del cuerpo de la petición y
     * el formulario destino. Devuelve los valores listos para el INSERT. Corta con
     * `ErrorResponse::send()` ante datos inválidos. NO cubre `label` ni `row_filter`
     * (difieren entre simple y lote).
     *
     * @return array{expose_list:int,expose_detail:int,expose_map:int,expose_stats:int,
     *   expose_attachments:int,field_filter:?string,team_filter:?string,
     *   stats_status:?string,password_hash:?string,expires_at:?string}
     */
    public static function parseSettings(array $body, array $form): array {
        // Qué expone el enlace (al menos uno; la lista es lo razonable por defecto).
        $exposeList   = !empty($body['expose_list']) ? 1 : 0;
        $exposeDetail = !empty($body['expose_detail']) ? 1 : 0;
        $exposeMap    = !empty($body['expose_map']) ? 1 : 0;
        $exposeStats  = !empty($body['expose_stats']) ? 1 : 0;
        if (!$exposeList && !$exposeDetail && !$exposeMap && !$exposeStats) {
            ErrorResponse::send('VALIDATION_ERROR', 'El enlace debe exponer al menos una vista');
        }

        // Filtro por columna (ocultar campos en el enlace): canónico o NULL.
        $fieldRule = FieldScope::normalize($body['field_filter'] ?? null);
        $fieldJson = $fieldRule ? json_encode($fieldRule, JSON_UNESCAPED_UNICODE) : null;

        // Alcance FIJO por equipo: lista de claves seleccionadas (valores de
        // stats_team_field; '__none__' = sin equipo). Solo si el formulario tiene
        // equipo configurado. Lista vacía / sin equipo → NULL (= todos los equipos).
        $teamJson = null;
        if (!empty($form['stats_team_field']) && isset($body['team_filter']) && is_array($body['team_filter'])) {
            $keys = array_values(array_unique(array_filter(
                array_map(fn($v) => trim((string) $v), $body['team_filter']),
                fn($v) => $v !== ''
            )));
            if ($keys) {
                $teamJson = json_encode($keys, JSON_UNESCAPED_UNICODE);
            }
        }

        // Alcance por estado de revisión: 'all' (NULL) o 'approved'.
        $statsStatus = ($body['stats_status'] ?? 'all') === 'approved' ? 'approved' : null;

        // Contraseña según política global.
        $policy   = Settings::sharePasswordPolicy();
        $password = isset($body['password']) ? (string) $body['password'] : '';
        $hash     = null;
        if ($policy === 'off') {
            $password = '';
        }
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
        } elseif ($policy === 'required') {
            ErrorResponse::send('VALIDATION_ERROR', 'Este servidor exige contraseña en los enlaces');
        }

        // Adjuntos: doble capa. Solo si la política global lo permite Y el enlace
        // tiene contraseña (los adjuntos suelen contener PII sensible).
        $exposeAttachments = 0;
        if (!empty($body['expose_attachments'])) {
            if (Settings::shareAttachmentsPolicy() !== 'require_password') {
                ErrorResponse::send('VALIDATION_ERROR', 'Este servidor no permite exponer adjuntos en enlaces');
            }
            if ($hash === null) {
                ErrorResponse::send('VALIDATION_ERROR', 'Exponer adjuntos requiere proteger el enlace con contraseña');
            }
            $exposeAttachments = 1;
        }

        // Caducidad opcional (YYYY-MM-DD o datetime). En blanco → sin caducidad.
        $expiresAt = null;
        $rawExp    = trim((string) ($body['expires_at'] ?? ''));
        if ($rawExp !== '') {
            $ts = strtotime($rawExp);
            if ($ts === false) {
                ErrorResponse::send('VALIDATION_ERROR', 'Fecha de caducidad no válida');
            }
            if ($ts < time()) {
                ErrorResponse::send('VALIDATION_ERROR', 'La caducidad debe estar en el futuro');
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        return [
            'expose_list'        => $exposeList,
            'expose_detail'      => $exposeDetail,
            'expose_map'         => $exposeMap,
            'expose_stats'       => $exposeStats,
            'expose_attachments' => $exposeAttachments,
            'field_filter'       => $fieldJson,
            'team_filter'        => $teamJson,
            'stats_status'       => $statsStatus,
            'password_hash'      => $hash,
            'expires_at'         => $expiresAt,
        ];
    }

    /**
     * Combina un filtro de filas BASE (componible con Y en la raíz) con una
     * condición distintiva fija `campo = valor`, para la creación de enlaces en
     * LOTE. Devuelve el row_filter canónico de RowScope. El llamador garantiza que
     * el filtro base es AND-componible (raíz 'all' o ≤1 grupo).
     */
    public static function withScopeValue(?array $baseRule, string $field, string $value): ?array {
        $base   = RowScope::normalize($baseRule);
        $groups = $base['groups'] ?? [];
        $groups[] = ['match' => 'all', 'conditions' => [
            ['field' => $field, 'op' => 'in', 'values' => [$value]],
        ]];
        return RowScope::normalize(['match' => 'all', 'groups' => $groups]);
    }

    /**
     * Resuelve un token a su fila de `share_links` si el enlace está ACTIVO
     * (no revocado y no caducado). Devuelve null en cualquier otro caso.
     */
    public static function resolve(string $token): ?array {
        if ($token === '') return null;
        $row = DB::run(
            'SELECT sl.*, f.name AS form_name, f.schema_json, f.active AS form_active,
                    f.deployment_status, f.last_synced_at,
                    f.stats_team_field, f.stats_enumerator_field
             FROM share_links sl
             JOIN forms f ON f.id = sl.form_id
             WHERE sl.token = ?',
            [$token]
        )->fetch();
        if (!$row) return null;
        if ($row['revoked_at'] !== null) return null;
        if (!(int) $row['form_active']) return null;
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        return $row;
    }

    /** Regla de scoping por filas del enlace (canónica) o null si no restringe. */
    public static function rule(array $link): ?array {
        return RowScope::normalize(
            $link['row_filter'] ? json_decode((string) $link['row_filter'], true) : null
        );
    }

    /**
     * Alcance FIJO por equipo del enlace como regla RowScope (o null = sin restricción).
     * Combina `team_filter` (selección de claves) con el `stats_team_field` del formulario.
     */
    public static function teamRule(array $link): ?array {
        $keys = isset($link['team_filter']) && $link['team_filter'] !== null
            ? json_decode((string) $link['team_filter'], true) : null;
        return is_array($keys)
            ? RowScope::teamRule($link['stats_team_field'] ?? null, $keys)
            : null;
    }

    /** Alcance por estado de revisión del enlace: 'approved' (u otro estado válido) o null. */
    public static function statusScope(array $link): ?string {
        $s = $link['stats_status'] ?? null;
        return in_array($s, ValidationStatus::STATUSES, true) ? $s : null;
    }

    /**
     * Condición SQL (+ params) del alcance por FILAS del enlace = row_filter AND equipos,
     * sobre la columna JSON dada. Se ANDan a nivel SQL para no depender de la profundidad
     * de RowScope. NO incluye el filtro por estado (ese va aparte, por `submission_uid`).
     *
     * @return array{0:string,1:array}
     */
    public static function rowSql(array $link, string $jsonCol): array {
        [$s1, $p1] = RowScope::sqlCondition(self::rule($link), $jsonCol);
        [$s2, $p2] = RowScope::sqlCondition(self::teamRule($link), $jsonCol);
        return ["($s1) AND ($s2)", array_merge($p1, $p2)];
    }

    /** ¿El payload de un envío entra en el alcance por filas (row_filter + equipos) del enlace? */
    public static function matchesScope(array $link, array $payload): bool {
        return RowScope::matches(self::rule($link), $payload)
            && RowScope::matches(self::teamRule($link), $payload);
    }

    /** ¿El enlace está protegido con contraseña? */
    public static function hasPassword(array $link): bool {
        return ($link['password_hash'] ?? null) !== null && $link['password_hash'] !== '';
    }

    /** Verifica la contraseña de un enlace protegido. */
    public static function verifyPassword(array $link, string $password): bool {
        if (!self::hasPassword($link)) return true;
        return password_verify($password, (string) $link['password_hash']);
    }

    /** Registra un acceso (contador + última fecha). */
    public static function recordAccess(int $id): void {
        DB::run(
            'UPDATE share_links SET access_count = access_count + 1, last_accessed_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    // ---------- Ticket de acceso (HMAC) para enlaces con contraseña ----------

    private static function b64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $txt): string {
        return base64_decode(strtr($txt, '-_', '+/')) ?: '';
    }

    private static function sign(string $data): string {
        return self::b64url(hash_hmac('sha256', 'share:' . $data, JWT_SECRET, true));
    }

    /** Emite un ticket firmado que acredita haber superado la contraseña del enlace. */
    public static function issueTicket(string $token): string {
        $body = self::b64url(json_encode(['t' => $token, 'exp' => time() + self::TICKET_TTL]));
        return $body . '.' . self::sign($body);
    }

    /** ¿El ticket es válido (firma + no caducado) para este token de enlace? */
    public static function verifyTicket(?string $ticket, string $token): bool {
        if (!$ticket) return false;
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) return false;
        [$body, $sig] = $parts;
        if (!hash_equals(self::sign($body), $sig)) return false;
        $payload = json_decode(self::b64urlDecode($body), true);
        if (!is_array($payload)) return false;
        if (($payload['t'] ?? null) !== $token) return false;
        if (($payload['exp'] ?? 0) < time()) return false;
        return true;
    }

    /**
     * Guard común de los endpoints públicos: resuelve el token, comprueba la
     * capacidad pedida ('list'|'detail'|'map') y, si el enlace tiene contraseña,
     * exige un ticket válido. Corta con el error adecuado o devuelve la fila.
     */
    public static function requireAccess(string $token, string $capability): array {
        self::throttle();
        $link = self::resolve($token);
        if ($link === null) {
            ErrorResponse::send('NOT_FOUND', 'Enlace no válido o caducado');
        }
        $exposeCol = match ($capability) {
            'list'        => 'expose_list',
            'detail'      => 'expose_detail',
            'map'         => 'expose_map',
            'stats'       => 'expose_stats',
            'attachments' => 'expose_attachments',
            default       => null,
        };
        if ($exposeCol === null || !(int) $link[$exposeCol]) {
            ErrorResponse::send('NOT_FOUND', 'Recurso no disponible en este enlace');
        }
        // Los adjuntos respetan además la política global EN VIVO: si el admin la
        // vuelve a 'off', deja de servirlos aunque el enlace tenga la columna a 1
        // (kill-switch, no solo validación en el momento de crear el enlace).
        if ($capability === 'attachments' && Settings::shareAttachmentsPolicy() !== 'require_password') {
            ErrorResponse::send('NOT_FOUND', 'Recurso no disponible en este enlace');
        }
        if (self::hasPassword($link)) {
            $ticket = $_SERVER['HTTP_X_SHARE_TICKET'] ?? ($_GET['k'] ?? null);
            if (!self::verifyTicket($ticket ? (string) $ticket : null, $token)) {
                ErrorResponse::send('SHARE_PASSWORD_REQUIRED');
            }
        }
        return $link;
    }
}
