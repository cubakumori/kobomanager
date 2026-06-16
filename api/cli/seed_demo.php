<?php
/**
 * CLI: sembrar envíos SINTÉTICOS en la caché local para una instancia de demo.
 *
 * Genera datos FALSOS leyendo el esquema cacheado del formulario (`forms.schema_json`,
 * el mismo que produce FormSchema::normalize) y los inserta directamente en
 * `submissions_cache` — NO escribe nada en KoboToolbox. Por eso:
 *   - controla las fechas (envíos repartidos en las últimas semanas → estadísticas
 *     por día/mes/hora y tendencias con forma realista, imposible si se inyectaran en
 *     Kobo, que sella `_submission_time` con la hora de recepción);
 *   - no toca la cuenta Kobo ni consume cuota de API;
 *   - usa exactamente el mismo formato de payload, `submitted_at` (anclado en UTC) y
 *     `search_text` que SubmissionSync, para que la app no note la diferencia.
 *
 * Es una herramienta de OPERADOR para montar una demo o un entorno de pruebas; no
 * forma parte de la app y no tiene equivalente en la UI (generar datos falsos sobre
 * formularios reales sería un riesgo). Los envíos sembrados se marcan con
 * `_km_seed: true` en el payload para poder limpiarlos con --clear.
 *
 * IMPORTANTE: como estos envíos no existen en Kobo, un `sync` los reconciliaría y
 * borraría. Una instancia de demo sembrada NO debe tener cron de sync, solo de reset.
 *
 * Uso:
 *   php api/cli/seed_demo.php <form_id> <count> [opciones]
 *
 * Opciones:
 *   --days N       Repartir los envíos en los últimos N días (defecto 60).
 *   --reviews PCT  Marcar como revisado (approved/on_hold/rejected) el PCT % de los
 *                  envíos (defecto 35; 0 = ninguno).
 *   --clear        Borrar primero los envíos sembrados (con `_km_seed`) de ese form.
 *
 * Ejemplo:
 *   php api/cli/seed_demo.php 1 40 --days 90 --reviews 40
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DB.php';
require __DIR__ . '/../lib/FormSchema.php';
require __DIR__ . '/../lib/SubmissionSearch.php';

function ok(string $msg): void   { echo "  ✓ $msg\n"; }
function fail(string $msg): never { fwrite(STDERR, "  ✗ $msg\n"); exit(1); }

$args   = array_slice($argv, 1);
$formId = null;
$count  = null;
$days   = 60;
$reviewsPct = 35;
$clear  = false;

$positional = [];
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--clear') { $clear = true; }
    elseif ($a === '--days')    { $days = (int) ($args[++$i] ?? 0); }
    elseif ($a === '--reviews') { $reviewsPct = (int) ($args[++$i] ?? 0); }
    else { $positional[] = $a; }
}
$formId = isset($positional[0]) ? (int) $positional[0] : 0;
$count  = isset($positional[1]) ? (int) $positional[1] : 0;

if ($formId <= 0 || $count <= 0) {
    fwrite(STDERR, "Uso: php api/cli/seed_demo.php <form_id> <count> [--days N] [--reviews PCT] [--clear]\n");
    exit(1);
}
if ($days < 1)  $days = 1;
$reviewsPct = max(0, min(100, $reviewsPct));

echo "KoboManager — sembrado de demo\n\n";

// ---------- Formulario y esquema ----------
$form = DB::run('SELECT id, name, kobo_asset_uid, schema_json FROM forms WHERE id = ?', [$formId])->fetch();
if (!$form) {
    fail("No existe el formulario id=$formId.");
}
if (empty($form['schema_json'])) {
    fail("El formulario «{$form['name']}» no tiene esquema cacheado. Sincronízalo primero (forms sync).");
}
$schema = json_decode($form['schema_json'], true);
if (!is_array($schema) || empty($schema['fields'])) {
    fail("El esquema cacheado del formulario no es válido o no tiene campos.");
}
$fields   = $schema['fields'];
$choices  = $schema['choices'] ?? [];
$meta     = $schema['meta'] ?? [];
$assetUid = (string) ($form['kobo_asset_uid'] ?? 'demo');
ok("Formulario «{$form['name']}» (id=$formId) — " . count($fields) . ' campos en el esquema');

$optionLabels = FormSchema::searchOptionLabels($schema);

// ---------- Limpieza previa ----------
if ($clear) {
    // Primero las revisiones de esos envíos sembrados, para no dejar filas huérfanas
    // en submission_reviews (apuntarían a un submission_uid que ya no existe).
    DB::run(
        "DELETE sr FROM submission_reviews sr
         JOIN submissions_cache sc ON sc.submission_uid = sr.submission_uid
         WHERE sc.form_id = ? AND JSON_EXTRACT(sc.json_payload, '$._km_seed') = true",
        [$formId]
    );
    $del = DB::run(
        "DELETE FROM submissions_cache
         WHERE form_id = ? AND JSON_EXTRACT(json_payload, '$._km_seed') = true",
        [$formId]
    )->rowCount();
    ok("Borrados $del envíos sembrados previos (y sus revisiones).");
}

// ---------- Helpers de generación ----------
function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $hex = bin2hex($b);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
         . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

/** Tipos de adjunto: no se siembran (no existirían los ficheros en Kobo). */
function isAttachmentType(string $type): bool {
    return in_array($type, ['image', 'audio', 'video', 'file', 'background-audio'], true);
}

/** Punto aleatorio dentro del bounding box aproximado de Cuba. */
function randomGeopoint(): array {
    $lat = 19.8 + (random_int(0, 35000) / 10000);   // 19.8 .. 23.3
    $lon = -84.9 + (random_int(0, 108000) / 10000);  // -84.9 .. -74.1
    return [round($lat, 6), round($lon, 6)];
}

function randomInitials(): string {
    $L = str_split('ABCDEFGHIJLMNPRSTV');
    $n = random_int(2, 3);
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = $L[array_rand($L)] . '.';
    return implode(' ', $out);
}

/** Genera el valor de un campo según su tipo; null = omitir la clave del payload. */
function genValue(array $f, array $choices): ?string {
    $type = (string) $f['type'];
    $list = $f['list'] ?? null;

    if (str_starts_with($type, 'select_multiple')) {
        $opts = ($list && isset($choices[$list])) ? array_keys($choices[$list]) : [];
        if (!$opts) return null;
        shuffle($opts);
        $k = min(count($opts), random_int(1, 3));
        $pick = array_slice($opts, 0, $k);
        return implode(' ', $pick);
    }
    if (str_starts_with($type, 'select_one')) {
        $opts = ($list && isset($choices[$list])) ? array_keys($choices[$list]) : [];
        return $opts ? (string) $opts[array_rand($opts)] : null;
    }
    switch ($type) {
        case 'integer':
            return (string) random_int(0, 99);
        case 'decimal':
            return (string) round(random_int(0, 10000) / 100, 2);
        case 'geopoint':
            [$lat, $lon] = randomGeopoint();
            return "$lat $lon 0 0";
        case 'date':
            return (new DateTime("-" . random_int(0, 365) . " days"))->format('Y-m-d');
        case 'time':
            return sprintf('%02d:%02d', random_int(0, 23), random_int(0, 59));
        case 'datetime':
            return (new DateTime("-" . random_int(0, 365) . " days"))->format('Y-m-d\TH:i:s');
        case 'text':
        default:
            // Frase corta sintética; algo de variedad para los filtros de texto.
            $words = ['demo', 'ejemplo', 'prueba', 'sintético', 'muestra', 'campo', 'valor', 'dato'];
            shuffle($words);
            return ucfirst(implode(' ', array_slice($words, 0, random_int(1, 3))));
    }
}

// ---------- Generación ----------
$conn = DB::conn();
$conn->beginTransaction();

$now      = new DateTime('now', new DateTimeZone('UTC'));
$inserted = 0;
$seededUids = [];

for ($n = 0; $n < $count; $n++) {
    // Instante del envío: repartido en los últimos $days días, con hora sesgada al
    // horario de campo (6:00–21:59) para que «actividad por hora» tenga forma.
    $when = (clone $now)
        ->modify('-' . random_int(0, $days - 1) . ' days')
        ->setTime(random_int(6, 21), random_int(0, 59), random_int(0, 59));
    $submittedAt = $when->format('Y-m-d H:i:s');          // columna DATETIME (UTC)
    $isoUtc      = $when->format('Y-m-d\TH:i:s');          // _submission_time (UTC, sin offset)

    $uuid = uuid4();
    $kid  = random_int(700000000, 999999999);

    $payload = [];
    $geo = null;
    foreach ($fields as $key => $f) {
        $type = (string) $f['type'];
        if (isAttachmentType($type)) continue;             // sin adjuntos sintéticos

        // Iniciales para campos de texto que claramente piden un nombre corto.
        if ($type === 'text' && stripos((string) $f['leaf'], 'q4') !== false) {
            $payload[$key] = randomInitials();
            continue;
        }

        $v = genValue($f, $choices);
        if ($v === null) continue;

        // ~12% de los campos opcionales de texto/número se dejan vacíos, para que los
        // operadores «vacío / no vacío» de los filtros tengan datos donde lucir.
        if (in_array($type, ['text', 'integer', 'decimal'], true) && random_int(1, 100) <= 12) {
            continue;
        }

        if ($type === 'geopoint') {
            $payload[$key] = $v;
            $parts = explode(' ', $v);
            $geo = [(float) $parts[0], (float) $parts[1]];
            continue;
        }
        $payload[$key] = $v;
    }

    // Metadatos de marca de tiempo con sus nombres reales del esquema (start/end/today).
    if (!empty($meta['today'])) $payload[$meta['today']] = $when->format('Y-m-d');
    if (!empty($meta['end']))   $payload[$meta['end']]   = $when->format('Y-m-d\TH:i:s.000+00:00');
    if (!empty($meta['start'])) {
        $start = (clone $when)->modify('-' . random_int(1, 12) . ' minutes');
        $payload[$meta['start']] = $start->format('Y-m-d\TH:i:s.000+00:00');
    }

    // Campos internos, espejo de lo que devuelve Kobo (claves _*; las ignora la UI).
    $payload['_id']              = $kid;
    $payload['formhub/uuid']     = bin2hex(random_bytes(16));
    $payload['__version__']      = 'demoSeed00000000000000';
    $payload['meta/instanceID']  = 'uuid:' . $uuid;
    $payload['meta/rootUuid']    = 'uuid:' . $uuid;
    $payload['_xform_id_string'] = $assetUid;
    $payload['_uuid']            = $uuid;
    $payload['_attachments']     = [];
    $payload['_status']          = 'submitted_via_web';
    if ($geo !== null) $payload['_geolocation'] = $geo;
    $payload['_submission_time'] = $isoUtc;
    $payload['_tags']            = [];
    $payload['_notes']           = [];
    $payload['_validation_status'] = [];
    $payload['_submitted_by']    = null;
    $payload['_km_seed']         = true;   // marca de sembrado (para --clear)

    DB::run(
        'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, search_text, submitted_at, last_synced_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            json_payload = VALUES(json_payload), search_text = VALUES(search_text),
            submitted_at = VALUES(submitted_at), last_synced_at = NOW()',
        [$formId, $uuid, json_encode($payload, JSON_UNESCAPED_UNICODE),
         SubmissionSearch::textFor($payload, $optionLabels), $submittedAt]
    );
    $inserted++;
    $seededUids[] = $uuid;
}

$conn->commit();
ok("Insertados $inserted envíos sintéticos (repartidos en $days días).");

// ---------- Revisiones de ejemplo ----------
if ($reviewsPct > 0 && $seededUids) {
    $admin = DB::run("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
    if (!$admin) {
        ok('Sin admin activo: no se crean revisiones de ejemplo.');
    } else {
        $adminId = (int) $admin['id'];
        $statuses = ['approved', 'approved', 'on_hold', 'rejected']; // sesgo a aprobado
        $howMany  = (int) floor(count($seededUids) * $reviewsPct / 100);
        $pool     = $seededUids;
        shuffle($pool);
        $made = 0;
        foreach (array_slice($pool, 0, $howMany) as $uid) {
            DB::run(
                'INSERT INTO submission_reviews (submission_uid, user_id, status, comment, created_at) VALUES (?, ?, ?, ?, NOW())',
                [$uid, $adminId, $statuses[array_rand($statuses)], null]
            );
            $made++;
        }
        ok("Creadas $made revisiones de ejemplo (≈{$reviewsPct}%).");
    }
}

// Marca de frescura para que la UI no muestre «Sin sincronizar».
DB::run('UPDATE forms SET submissions_synced_at = NOW() WHERE id = ?', [$formId]);

echo "\nListo. Revisa la app para ver los datos sembrados.\n";
