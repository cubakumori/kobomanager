<?php

declare(strict_types=1);

/**
 * Tests de lib/Quality (control de calidad por equipo/encuestador).
 *
 * Puntos críticos: las banderas contra los umbrales (corta/larga por duración;
 * hueco corto/solapada por consecutividad del MISMO encuestador), que el solape
 * se marca SIEMPRE aunque el umbral de consecutividad esté desactivado, que la
 * cadena usa el máximo `end` visto (encuestas englobadas), la CLASIFICACIÓN del
 * solape (entre consecutivas vs con registro largo, con la referencia al
 * culpable), y que los envíos sin marcas de tiempo cuentan aparte sin banderas.
 */
final class QualityTest extends DbTestCase
{
    private function addSubmission(int $formId, array $payload): string
    {
        $uid = 'uid_' . bin2hex(random_bytes(6));
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, NOW())',
            [$formId, $uid, json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
        return $uid;
    }

    /** Envío con encuestador y tiempos (horas del mismo día, en ISO sin zona). */
    private function timed(int $formId, string $enum, string $start, string $end, array $extra = []): string
    {
        return $this->addSubmission($formId, $extra + [
            'enum'  => $enum,
            'start' => "2026-07-01T$start",
            'end'   => "2026-07-01T$end",
        ]);
    }

    /** Atajo: compute con encuestador por campo `enum` y sin equipo. */
    private function compute(int $formId, ?int $min, ?int $max, ?int $gap, ?array $scope = null): array
    {
        return Quality::compute($formId, null, $scope, null, 'es', null, 'enum', $min, $max, $gap);
    }

    /** Todas las violaciones del resultado, aplanadas, indexadas por uid. */
    private function violationsByUid(array $q): array
    {
        $out = [];
        foreach ($q['teams'] as $t) {
            foreach ($t['enumerators'] as $e) {
                foreach ($e['violations'] as $v) $out[$v['uid']] = $v;
            }
        }
        return $out;
    }

    public function testDurationFlags(): void
    {
        $formId = $this->makeForm();
        $short = $this->timed($formId, 'ana', '09:00:00', '09:02:00'); // 2 min < 4
        $ok    = $this->timed($formId, 'ana', '10:00:00', '10:30:00'); // 30 min
        $long  = $this->timed($formId, 'ana', '12:00:00', '13:30:00'); // 90 min > 60

        $q = $this->compute($formId, 4, 60, null);
        $this->assertSame(3, $q['total']);
        $this->assertSame(2, $q['flagged']);
        $this->assertSame(['short' => 1, 'long' => 1, 'short_gap' => 0, 'overlap' => 0, 'overlap_long' => 0, 'duplicate' => 0, 'gps' => 0], $q['flags']);

        $v = $this->violationsByUid($q);
        $this->assertSame(['short'], $v[$short]['flags']);
        $this->assertSame(120, $v[$short]['duration_s']);
        $this->assertSame(['long'], $v[$long]['flags']);
        $this->assertArrayNotHasKey($ok, $v);
    }

    public function testGapAndOverlapPerEnumeratorChain(): void
    {
        $formId = $this->makeForm();
        $a = $this->timed($formId, 'ana', '09:00:00', '09:10:00'); // primera: sin hueco
        $b = $this->timed($formId, 'ana', '09:12:00', '09:20:00'); // hueco 2 min < 4 → short_gap
        $c = $this->timed($formId, 'ana', '09:15:00', '09:40:00'); // empieza antes del fin máx (09:20) → overlap
        $d = $this->timed($formId, 'ana', '10:40:00', '10:50:00'); // hueco 60 min (vs fin máx 09:40) → ok
        // Otro encuestador: cadena propia, su primera encuesta no compara con las de ana.
        $e = $this->timed($formId, 'luis', '09:11:00', '09:13:00');

        $q = $this->compute($formId, null, null, 4);
        $this->assertSame(['short' => 0, 'long' => 0, 'short_gap' => 1, 'overlap' => 1, 'overlap_long' => 0, 'duplicate' => 0, 'gps' => 0], $q['flags']);

        $v = $this->violationsByUid($q);
        $this->assertSame(['short_gap'], $v[$b]['flags']);
        $this->assertSame(120, $v[$b]['gap_s']);
        // c se entrelaza con b (la inmediatamente anterior terminó DENTRO de su
        // ventana) → concurrencia real, sin referencia a registro largo.
        $this->assertSame(['overlap'], $v[$c]['flags']);
        $this->assertSame(-300, $v[$c]['gap_s']);
        $this->assertSame(-300, $v[$c]['gap_prev_s']);
        $this->assertNull($v[$c]['long_uid']);
        $this->assertArrayNotHasKey($a, $v);
        $this->assertArrayNotHasKey($d, $v);
        $this->assertArrayNotHasKey($e, $v);
    }

    public function testOverlapFlaggedEvenWithoutGapThreshold(): void
    {
        $formId = $this->makeForm();
        $this->timed($formId, 'ana', '09:00:00', '09:10:00');
        $b = $this->timed($formId, 'ana', '09:05:00', '09:15:00'); // solapada

        $q = $this->compute($formId, null, null, null); // TODOS los umbrales apagados
        $this->assertSame(1, $q['flagged']);
        $this->assertSame(1, $q['flags']['overlap']);
        $this->assertSame(['overlap'], $this->violationsByUid($q)[$b]['flags']);
    }

    /**
     * El caso «cascada» del FAQ de solapamiento: UN formulario dejado abierto
     * varias horas (apagón/pausa) engulle a las encuestas hechas dentro de su
     * ventana. Todas deben salir como `overlap_long` (con la referencia al
     * registro largo), NUNCA como `overlap` (concurrencia real) — incluida la
     * PRIMERA anidada, cuya «inmediatamente anterior» es el propio registro largo.
     */
    public function testLongRecordCascadeClassifiedAsOverlapLong(): void
    {
        $formId = $this->makeForm();
        $long = $this->timed($formId, 'ana', '08:00:00', '14:36:00'); // registro largo (6 h 36)
        $s1 = $this->timed($formId, 'ana', '10:35:00', '11:55:00');   // anidada: su anterior ES el largo
        $s2 = $this->timed($formId, 'ana', '12:08:00', '14:29:00');   // anterior (s1) ya terminó → cascada
        $s3 = $this->timed($formId, 'ana', '14:29:00', '14:35:00');   // ídem (huecoPrev = 0)
        $s4 = $this->timed($formId, 'ana', '14:37:00', '14:45:00');   // arranca tras el fin máximo → limpia

        // max=300 min: solo el registro largo (396 min) cae por `long`, y así se
        // le ve el «engulle» en su fila del drill-down.
        $q = $this->compute($formId, null, 300, null);
        $this->assertSame(0, $q['flags']['overlap']);
        $this->assertSame(3, $q['flags']['overlap_long']);
        $this->assertSame(1, $q['flags']['long']);

        $v = $this->violationsByUid($q);
        foreach ([$s1, $s2, $s3] as $uid) {
            $this->assertSame(['overlap_long'], $v[$uid]['flags']);
            $this->assertSame($long, $v[$uid]['long_uid']);
            $this->assertNotNull($v[$uid]['long_end_at']);
        }
        // El registro largo se reporta (bandera `long`) y declara a cuántas engulle.
        $this->assertSame(['long'], $v[$long]['flags']);
        $this->assertSame(3, $v[$long]['engulfs']);
        $this->assertArrayNotHasKey($s4, $v);
    }

    /**
     * Mixto: dentro de la ventana de un registro largo, dos encuestas que ADEMÁS
     * se entrelazan entre sí. La entrelazada es concurrencia real (`overlap`),
     * no ruido del registro largo.
     */
    public function testInterleaveInsideLongWindowIsStillRealOverlap(): void
    {
        $formId = $this->makeForm();
        $long = $this->timed($formId, 'ana', '08:00:00', '14:00:00'); // registro largo
        $a = $this->timed($formId, 'ana', '10:00:00', '10:30:00');    // anidada → overlap_long
        $b = $this->timed($formId, 'ana', '10:20:00', '10:50:00');    // se entrelaza con a → overlap

        $q = $this->compute($formId, null, null, null);
        $v = $this->violationsByUid($q);
        $this->assertSame(['overlap_long'], $v[$a]['flags']);
        $this->assertSame($long, $v[$a]['long_uid']);
        $this->assertSame(['overlap'], $v[$b]['flags']);
        $this->assertNull($v[$b]['long_uid']);
        $this->assertSame(1, $q['flags']['overlap']);
        $this->assertSame(1, $q['flags']['overlap_long']);
    }

    public function testUntimedSubmissionsCountedApartAndNeverFlagged(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['enum' => 'ana']);                                  // sin tiempos
        $this->addSubmission($formId, ['enum' => 'ana', 'start' => '2026-07-01T10:00:00',
                                       'end' => '2026-07-01T09:00:00']);                   // end < start
        $this->timed($formId, 'ana', '11:00:00', '11:01:00');                              // corta

        $q = $this->compute($formId, 4, null, 4);
        $this->assertSame(3, $q['total']);
        $this->assertSame(2, $q['untimed']);
        $this->assertSame(1, $q['flagged']); // solo la corta; las no evaluables no marcan
    }

    public function testTeamGroupingAndReviewStatus(): void
    {
        $formId = $this->makeForm();
        $flagged = $this->timed($formId, 'ana', '09:00:00', '09:01:00', ['team' => 'norte']);
        $this->timed($formId, 'ana', '10:00:00', '10:30:00', ['team' => 'norte']);
        $this->timed($formId, 'luis', '09:00:00', '09:30:00', ['team' => 'sur']);
        ValidationStatus::recordReview($flagged, $this->makeUser('admin'), 'app', 'on_hold');

        $q = Quality::compute($formId, null, null, null, 'es', 'team', 'enum', 4, null, null);
        $this->assertSame('team', $q['team_field']['key']);
        $this->assertCount(2, $q['teams']);
        // Equipos ordenados por infracciones desc: norte (1) antes que sur (0).
        $this->assertSame('norte', $q['teams'][0]['name']);
        $this->assertSame(2, $q['teams'][0]['count']);
        $this->assertSame(1, $q['teams'][0]['flagged']);
        $this->assertSame(0, $q['teams'][1]['flagged']);
        $this->assertSame('on_hold', $this->violationsByUid($q)[$flagged]['review_status']);
    }

    public function testRowScopeRestricts(): void
    {
        $formId = $this->makeForm();
        $this->timed($formId, 'ana', '09:00:00', '09:01:00', ['region' => 'norte']); // corta
        $this->timed($formId, 'luis', '09:00:00', '09:01:00', ['region' => 'sur']);  // corta, fuera de alcance

        $scope = RowScope::normalize(['conditions' => [['field' => 'region', 'values' => ['norte']]]]);
        $q = $this->compute($formId, 4, null, null, $scope);
        $this->assertSame(1, $q['total']);
        $this->assertSame(1, $q['flagged']);
    }

    public function testScopeReportsOnlyPendingAndOnHold(): void
    {
        $formId = $this->makeForm();
        $admin  = $this->makeUser('admin');
        $pend = $this->timed($formId, 'ana', '09:00:00', '09:01:00');   // corta, pendiente
        $held = $this->timed($formId, 'ana', '10:00:00', '10:01:00');   // corta, en espera
        $appr = $this->timed($formId, 'ana', '11:00:00', '11:01:00');   // corta, APROBADA
        $rej  = $this->timed($formId, 'luis', '09:00:00', '09:01:00');  // corta, RECHAZADA (único envío de luis)
        ValidationStatus::recordReview($held, $admin, 'app', 'on_hold');
        ValidationStatus::recordReview($appr, $admin, 'app', 'approved');
        ValidationStatus::recordReview($rej, $admin, 'app', 'rejected');

        $q = Quality::compute($formId, null, null, null, 'es', null, 'enum', 4, null, null, ['pending', 'on_hold']);
        $this->assertSame(2, $q['total']);    // solo pendiente + en espera cuentan
        $this->assertSame(4, $q['received']); // pero el total recibido conserva las 4
        $this->assertSame(2, $q['flagged']);
        // El «total recibido» conserva el contexto completo: el equipo tiene 4 envíos
        // (incluidos los de luis, que no se lista) y ana 3 (incluida su aprobada).
        $this->assertSame(2, $q['teams'][0]['count']);
        $this->assertSame(4, $q['teams'][0]['total']);
        $ana = $q['teams'][0]['enumerators'][0];
        $this->assertSame(2, $ana['count']);
        $this->assertSame(3, $ana['total']);
        $v = $this->violationsByUid($q);
        $this->assertArrayHasKey($pend, $v);
        $this->assertArrayHasKey($held, $v);
        $this->assertArrayNotHasKey($appr, $v);
        $this->assertArrayNotHasKey($rej, $v);
        // luis solo tiene envíos fuera de alcance → no aparece como encuestador.
        $names = array_map(fn($e) => $e['name'], $q['teams'][0]['enumerators']);
        $this->assertSame(['ana'], $names);

        // Con alcance 'all' (null) se reportan las cuatro.
        $all = Quality::compute($formId, null, null, null, 'es', null, 'enum', 4, null, null, null);
        $this->assertSame(4, $all['total']);
        $this->assertSame(4, $all['flagged']);
    }

    public function testChainUsesOutOfScopeNeighbors(): void
    {
        $formId = $this->makeForm();
        $admin  = $this->makeUser('admin');
        // A (aprobada) 09:00–09:10; B (pendiente) empieza a las 09:05 → solapa con A.
        $a = $this->timed($formId, 'ana', '09:00:00', '09:10:00');
        $b = $this->timed($formId, 'ana', '09:05:00', '09:15:00');
        ValidationStatus::recordReview($a, $admin, 'app', 'approved');

        $q = Quality::compute($formId, null, null, null, 'es', null, 'enum', null, null, null, ['pending', 'on_hold']);
        // La aprobada no se reporta, pero SÍ ancla la cadena: la pendiente sale solapada.
        $this->assertSame(1, $q['total']);
        $this->assertSame(1, $q['flags']['overlap']);
        $v = $this->violationsByUid($q);
        $this->assertSame(['overlap'], $v[$b]['flags']);
        $this->assertSame(-300, $v[$b]['gap_s']);
        $this->assertArrayNotHasKey($a, $v);
    }

    public function testHiddenTeamFieldFallsBackToNoTeamLevel(): void
    {
        $formId = $this->makeForm();
        $this->timed($formId, 'ana', '09:00:00', '09:01:00', ['team' => 'norte']);

        $fieldScope = FieldScope::normalize(['hidden' => ['team']]);
        $q = Quality::compute($formId, null, null, $fieldScope, 'es', 'team', 'enum', 4, null, null);
        $this->assertNull($q['team_field']); // no se agrupa por un campo oculto
        $this->assertCount(1, $q['teams']);
        $this->assertSame('—', $q['teams'][0]['name']);
    }

    // ---- Señales de fabricación: duplicados y GPS clavado ----

    public function testDuplicateAnswersFlaggedAcrossEnumerators(): void
    {
        $formId = $this->makeForm();
        // Mismo contenido (2 respuestas) en encuestadores DISTINTOS → ambos duplicados.
        $this->addSubmission($formId, ['enum' => 'ana',  'p1' => 'x', 'p2' => 'y']);
        $this->addSubmission($formId, ['enum' => 'luis', 'p1' => 'x', 'p2' => 'y']);
        // Contenido distinto → limpio.
        $this->addSubmission($formId, ['enum' => 'ana',  'p1' => 'x', 'p2' => 'z']);

        $q = $this->compute($formId, null, null, null);
        $this->assertSame(2, $q['flags']['duplicate']);
        $this->assertSame(2, $q['flagged']);
    }

    public function testNearEmptySubmissionsNeverDuplicates(): void
    {
        $formId = $this->makeForm();
        // 0 o 1 respuestas de contenido (el campo del encuestador se excluye):
        // no forman grupo aunque coincidan.
        $this->addSubmission($formId, ['enum' => 'ana', 'p1' => 'x']);
        $this->addSubmission($formId, ['enum' => 'ana', 'p1' => 'x']);
        $this->addSubmission($formId, ['enum' => 'ana']);
        $this->addSubmission($formId, ['enum' => 'ana']);

        $q = $this->compute($formId, null, null, null);
        $this->assertSame(0, $q['flags']['duplicate']);
    }

    public function testDuplicateSensitivityDial(): void
    {
        $formId = $this->makeForm();
        // Dos envíos con UNA sola respuesta de contenido, idéntica.
        $this->addSubmission($formId, ['enum' => 'ana',  'p1' => 'x']);
        $this->addSubmission($formId, ['enum' => 'luis', 'p1' => 'x']);

        // Default (2): una respuesta no basta → limpio.
        $def = Quality::compute($formId, null, null, null, 'es', null, 'enum', null, null, null);
        $this->assertSame(0, $def['flags']['duplicate']);
        $this->assertSame(2, $def['thresholds']['dup_min_answers']);

        // Dial a 1: ahora sí se marcan ambos.
        $sensitive = Quality::compute($formId, null, null, null, 'es', null, 'enum', null, null, null, null, 1);
        $this->assertSame(2, $sensitive['flags']['duplicate']);
        $this->assertSame(1, $sensitive['thresholds']['dup_min_answers']);
    }

    public function testDuplicateSignalDisabledWhenNull(): void
    {
        $formId = $this->makeForm();
        // Copia exacta con 2 respuestas: se marcaría con el default.
        $this->addSubmission($formId, ['enum' => 'ana',  'p1' => 'x', 'p2' => 'y']);
        $this->addSubmission($formId, ['enum' => 'luis', 'p1' => 'x', 'p2' => 'y']);

        $off = Quality::compute($formId, null, null, null, 'es', null, 'enum', null, null, null, null, null);
        $this->assertSame(0, $off['flags']['duplicate']);
        $this->assertNull($off['thresholds']['dup_min_answers']);
    }

    public function testRepeatedGpsFlaggedPerEnumerator(): void
    {
        $formId = $this->makeForm();
        // Ana: 3 envíos con el MISMO punto exacto (≥ GPS_MIN_REPEATS) → los 3 marcados.
        foreach ([1, 2, 3] as $i) {
            $this->addSubmission($formId, ['enum' => 'ana', 'p' => "a$i", 'q' => $i, '_geolocation' => [23.1136, -82.3666]]);
        }
        // Luis: mismo punto pero solo 2 veces → limpio. Sin coordenadas → no participa.
        $this->addSubmission($formId, ['enum' => 'luis', 'p' => 'b1', 'q' => 1, '_geolocation' => [23.1136, -82.3666]]);
        $this->addSubmission($formId, ['enum' => 'luis', 'p' => 'b2', 'q' => 2, '_geolocation' => [23.1136, -82.3666]]);
        $this->addSubmission($formId, ['enum' => 'luis', 'p' => 'b3', 'q' => 3, '_geolocation' => [null, null]]);

        $q = $this->compute($formId, null, null, null);
        $this->assertTrue($q['gps_enabled']);
        $this->assertSame(3, $q['flags']['gps']);
        $enums = $q['teams'][0]['enumerators'];
        $ana = array_values(array_filter($enums, fn($e) => $e['name'] === 'ana'))[0];
        $this->assertSame(3, $ana['flags']['gps']);
    }

    public function testGpsInactiveWithoutCoordinates(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['enum' => 'ana', 'p' => 'x', 'q' => 'y']);

        $q = $this->compute($formId, null, null, null);
        $this->assertFalse($q['gps_enabled']);
        $this->assertSame(0, $q['flags']['gps']);
    }

    // ---- Tendencia semanal ----

    public function testReviewSummaryCountsAllStatusesRegardlessOfScope(): void
    {
        $formId = $this->makeForm();
        $admin  = $this->makeUser('admin');
        $this->timed($formId, 'ana', '09:00:00', '09:30:00', ['team' => 'norte']); // pendiente
        $held = $this->timed($formId, 'ana', '10:00:00', '10:30:00', ['team' => 'norte']);
        $appr = $this->timed($formId, 'ana', '11:00:00', '11:30:00', ['team' => 'norte']);
        // luis: único envío, RECHAZADO → fuera del alcance por estado, pero debe seguir
        // apareciendo en el resumen de estado.
        $rej = $this->timed($formId, 'luis', '09:00:00', '09:30:00', ['team' => 'sur']);
        ValidationStatus::recordReview($held, $admin, 'app', 'on_hold');
        ValidationStatus::recordReview($appr, $admin, 'app', 'approved');
        ValidationStatus::recordReview($rej, $admin, 'app', 'rejected');

        // Alcance de reporte = solo pendientes/en espera (luis no se lista en infracciones).
        $q = Quality::compute($formId, null, null, null, 'es', 'team', 'enum', null, null, null, ['pending', 'on_hold']);

        $rs = [];
        foreach ($q['review_summary'] as $t) $rs[$t['name']] = $t;
        // norte: ana con las 3 (1 pendiente, 1 en espera, 1 aprobada).
        $this->assertSame(['pending' => 1, 'approved' => 1, 'on_hold' => 1, 'rejected' => 0], $rs['norte']['status']);
        $this->assertSame(3, $rs['norte']['total']);
        $this->assertSame('ana', $rs['norte']['enumerators'][0]['name']);
        // sur: luis sigue presente pese a estar rechazado (y fuera del alcance de infracciones).
        $this->assertSame(['pending' => 0, 'approved' => 0, 'on_hold' => 0, 'rejected' => 1], $rs['sur']['status']);
        $this->assertSame('luis', $rs['sur']['enumerators'][0]['name']);
    }

    public function testByWeekTrendCountsAllReceived(): void
    {
        $formId = $this->makeForm();
        // Semana del 2026-06-29 (W27): 2 envíos, 1 corto. Semana W28: 1 envío limpio.
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at) VALUES (?, ?, ?, ?)',
            [$formId, 'w1', json_encode(['enum' => 'ana', 'start' => '2026-06-30T10:00:00', 'end' => '2026-06-30T10:01:00']), '2026-06-30 10:01:00']
        );
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at) VALUES (?, ?, ?, ?)',
            [$formId, 'w2', json_encode(['enum' => 'ana', 'start' => '2026-06-30T11:00:00', 'end' => '2026-06-30T11:30:00']), '2026-06-30 11:30:00']
        );
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at) VALUES (?, ?, ?, ?)',
            [$formId, 'w3', json_encode(['enum' => 'ana', 'start' => '2026-07-07T09:00:00', 'end' => '2026-07-07T09:30:00']), '2026-07-07 09:30:00']
        );
        // La bandera del envío corto viene del umbral min=4 min; el alcance por estado
        // NO afecta a la tendencia (se aprueba el corto y debe seguir contando).
        ValidationStatus::recordReview('w1', $this->makeUser('admin'), 'app', 'approved');

        $q = Quality::compute($formId, null, null, null, 'es', null, 'enum', 4, null, null, ['pending', 'on_hold']);
        $this->assertSame([
            ['week' => '2026-W27', 'total' => 2, 'flagged' => 1, 'pct' => 50.0],
            ['week' => '2026-W28', 'total' => 1, 'flagged' => 0, 'pct' => 0.0],
        ], $q['by_week']);
    }

    // ---- Atajo «aprobar en lote los admisibles»: conjunto admisible-pendiente ----

    /** Solo las PENDIENTES sin ninguna bandera entran en admissible_pending. */
    public function testAdmissiblePendingExposesOnlyUnflagged(): void
    {
        $formId = $this->makeForm();
        $ok    = $this->timed($formId, 'ana', '10:00:00', '10:30:00'); // 30 min: admisible
        $short = $this->timed($formId, 'ana', '11:00:00', '11:02:00'); // 2 min < 4: bandera
        $long  = $this->timed($formId, 'ana', '13:00:00', '14:30:00'); // 90 min > 60: bandera

        $q = $this->compute($formId, 4, 60, null); // gap desactivado: solo banderas de duración
        $uids = array_column($q['admissible_pending'], 'uid');
        $this->assertSame([$ok], $uids);
        $this->assertNotContains($short, $uids);
        $this->assertNotContains($long, $uids);

        // Helper sin Índice de riesgo: devuelve tal cual, sin exclusiones.
        $picked = Quality::admissiblePendingUids($q, null);
        $this->assertSame([$ok], $picked['uids']);
        $this->assertSame(0, $picked['high_risk_excluded']);
        $this->assertFalse($picked['risk_active']);
    }

    /** Una encuesta sin bandera pero NO pendiente (aprobada) no es admisible. */
    public function testAdmissibleExcludesNonPending(): void
    {
        $formId = $this->makeForm();
        $pending  = $this->timed($formId, 'ana', '10:00:00', '10:30:00');
        $approved = $this->timed($formId, 'ana', '12:00:00', '12:30:00');
        ValidationStatus::recordReview($approved, $this->makeUser('admin'), 'app', 'approved');

        // qc_scope 'all' (scopeStatuses null): ambas están «en alcance», pero solo la
        // pendiente es admisible.
        $q = Quality::compute($formId, null, null, null, 'es', null, 'enum', 4, 60, null);
        $uids = array_column($q['admissible_pending'], 'uid');
        $this->assertSame([$pending], $uids);
    }

    /** El conjunto respeta el alcance por estado (qc_scope): si los pendientes no están
     *  en alcance, no hay admisibles. */
    public function testAdmissibleRespectsQcScope(): void
    {
        $formId = $this->makeForm();
        $this->timed($formId, 'ana', '10:00:00', '10:30:00'); // pendiente, sin bandera

        $q = Quality::compute($formId, null, null, null, 'es', null, 'enum', 4, 60, null, ['on_hold']);
        $this->assertSame([], $q['admissible_pending']);
    }

    /** Con el Índice de riesgo activo, el helper excluye a los encuestadores de alto
     *  riesgo (índice ≥ Risk::SUSPICION_Z) por el par de nombres (equipo, encuestador). */
    public function testAdmissiblePendingUidsExcludesHighRisk(): void
    {
        // Función PURA: se construyen los resultados a mano (sin BD) para aislar la regla.
        $qualityResult = ['admissible_pending' => [
            ['uid' => 'u_ana',  'team' => '—', 'enum' => 'ana'],
            ['uid' => 'u_luis', 'team' => '—', 'enum' => 'luis'],
            ['uid' => 'u_ana2', 'team' => '—', 'enum' => 'ana'],
        ]];
        $riskResult = ['enabled' => true, 'teams' => [[
            'name' => '—',
            'enumerators' => [
                ['name' => 'ana',  'index' => Risk::SUSPICION_Z + 0.5], // alto riesgo → fuera
                ['name' => 'luis', 'index' => 0.3],                     // bajo → dentro
            ],
        ]]];

        $picked = Quality::admissiblePendingUids($qualityResult, $riskResult);
        $this->assertSame(['u_luis'], $picked['uids']);
        $this->assertSame(2, $picked['high_risk_excluded']); // u_ana + u_ana2
        $this->assertTrue($picked['risk_active']);

        // Un Índice de riesgo deshabilitado (enabled=false) no excluye a nadie.
        $none = Quality::admissiblePendingUids($qualityResult, ['enabled' => false, 'teams' => []]);
        $this->assertSame(['u_ana', 'u_luis', 'u_ana2'], $none['uids']);
        $this->assertSame(0, $none['high_risk_excluded']);
        $this->assertFalse($none['risk_active']);
    }

    // ---- Normalización de miembro/equipo de texto libre (forms.member_normalize) ----

    public function testNormalizationMergesVariantsWithinTeamOnly(): void
    {
        $formId = $this->makeForm(); // member_normalize = 'normalize' (default)
        // La misma persona con tres grafías EN EL MISMO equipo (que también varía)…
        $this->addSubmission($formId, ['team' => 'Norte',  'enum' => 'C. M. S.']);
        $this->addSubmission($formId, ['team' => 'norte',  'enum' => 'c m s']);
        $this->addSubmission($formId, ['team' => 'NORTE ', 'enum' => 'C.M.S']);
        // …y un HOMÓNIMO en otro equipo: NO se mezcla (fusión dentro del equipo).
        $this->addSubmission($formId, ['team' => 'Sur', 'enum' => 'C. M. S.']);

        $q = Quality::compute($formId, null, null, null, 'es', 'team', 'enum', null, null, null);

        $this->assertCount(2, $q['teams']);
        $byName = [];
        foreach ($q['teams'] as $t) $byName[$t['name']] = $t;
        // Etiqueta = grafía más frecuente (empate 1-1-1 → la menor alfabética: 'NORTE '… no:
        // pickLabel ordena por grafía; con 1-1-1 gana 'NORTE ' por orden ksort+arsort estable).
        $norte = $byName['NORTE '] ?? $byName['Norte'] ?? $byName['norte'] ?? null;
        $this->assertNotNull($norte, 'el equipo fusionado existe con alguna de sus grafías');
        $this->assertSame(3, $norte['total']);
        $this->assertCount(1, $norte['enumerators']); // UNA persona, no tres
        $this->assertSame(3, $norte['enumerators'][0]['total']);

        $sur = $byName['Sur'];
        $this->assertSame(1, $sur['total']);
        $this->assertCount(1, $sur['enumerators']);
    }

    public function testNormalizationRawModeKeepsVariantsApart(): void
    {
        $formId = $this->makeForm();
        DB::run("UPDATE forms SET member_normalize = 'raw' WHERE id = ?", [$formId]);
        $this->addSubmission($formId, ['team' => 'Norte', 'enum' => 'C. M. S.']);
        $this->addSubmission($formId, ['team' => 'Norte', 'enum' => 'c m s']);

        $q = Quality::compute($formId, null, null, null, 'es', 'team', 'enum', null, null, null);
        $this->assertCount(1, $q['teams']);
        $this->assertCount(2, $q['teams'][0]['enumerators']); // sin fusión
    }

    public function testAliasModeRemapsSpellingFamilies(): void
    {
        $formId = $this->makeForm();
        DB::run("UPDATE forms SET member_normalize = 'alias' WHERE id = ?", [$formId]);
        // «JLVH» es una errata de «JLHV» (baile de letras: la normalización no las une).
        DB::run(
            "INSERT INTO member_aliases (form_id, axis, from_key, to_value) VALUES (?, 'member', 'jlvh', 'JLHV')",
            [$formId]
        );
        $this->addSubmission($formId, ['team' => 'Norte', 'enum' => 'JLHV']);
        $this->addSubmission($formId, ['team' => 'Norte', 'enum' => 'JLVH']);
        $this->addSubmission($formId, ['team' => 'Norte', 'enum' => 'jlhv ']);

        $q = Quality::compute($formId, null, null, null, 'es', 'team', 'enum', null, null, null);
        $this->assertCount(1, $q['teams'][0]['enumerators']);
        $this->assertSame('JLHV', $q['teams'][0]['enumerators'][0]['name']); // canónico del alias
        $this->assertSame(3, $q['teams'][0]['enumerators'][0]['total']);
    }
}
