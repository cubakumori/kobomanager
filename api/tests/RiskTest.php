<?php

declare(strict_types=1);

/**
 * Tests de lib/Risk (índice de riesgo por encuestador/equipo).
 *
 * Fase 1 — percentmatch (similitud de respuestas del mismo encuestador, señal
 * principal del curbstoning) + el marco de z robusto vs pares, el gate de N mínimo,
 * el opt-in, el muestreo acotado y la mezcla de estado (compañero).
 */
final class RiskTest extends DbTestCase
{
    private function add(int $formId, array $payload): string
    {
        $uid = 'uid_' . bin2hex(random_bytes(6));
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, NOW())',
            [$formId, $uid, json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
        return $uid;
    }

    private function addAt(int $formId, array $payload, string $submittedAt): string
    {
        $uid = 'uid_' . bin2hex(random_bytes(6));
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, ?)',
            [$formId, $uid, json_encode($payload, JSON_UNESCAPED_UNICODE), $submittedAt]
        );
        return $uid;
    }

    /** Esquema mínimo normalizado: mapa ruta => tipo XLSForm. */
    private function schema(array $fieldTypes): array
    {
        $fields = [];
        foreach ($fieldTypes as $path => $type) {
            $fields[$path] = ['leaf' => $path, 'type' => $type, 'list' => null,
                'multi' => str_starts_with($type, 'select_multiple'), 'label' => []];
        }
        return ['fields' => $fields, 'choices' => [], 'meta' => [], 'languages' => [null], 'meta_fields' => []];
    }

    /** compute sin equipo, encuestador por campo `enum`. */
    private function compute(int $formId, ?int $minN, ?array $scope = null, ?array $scopeStatuses = null): array
    {
        return Risk::compute($formId, null, $scope, null, 'es', null, 'enum', $minN, $scopeStatuses);
    }

    private function computeS(int $formId, array $schema, ?int $minN): array
    {
        return Risk::compute($formId, $schema, null, null, 'es', null, 'enum', $minN);
    }

    private function enumByName(array $risk, string $name): ?array
    {
        foreach ($risk['teams'] as $t) {
            foreach ($t['enumerators'] as $e) {
                if ($e['name'] === $name) return $e;
            }
        }
        return null;
    }

    private function component(array $enum, string $key): ?array
    {
        foreach ($enum['components'] as $c) {
            if ($c['key'] === $key) return $c;
        }
        return null;
    }

    public function testDisabledWhenMinNNull(): void
    {
        $formId = $this->makeForm();
        $this->add($formId, ['enum' => 'ana', 'p1' => 'a', 'p2' => 'b']);

        $r = $this->compute($formId, null);
        $this->assertFalse($r['enabled']);
        $this->assertNull($r['min_n']);
        $this->assertSame([], $r['teams']);
    }

    public function testPercentMatchIdentifiesFabricator(): void
    {
        $formId = $this->makeForm();
        // fab: 3 envíos IDÉNTICOS → percentmatch 1.0. ann/ben: todo distinto → 0.0.
        // cid: p1 fijo, p2 variable en un par → intermedio.
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'fab', 'p1' => 'a', 'p2' => 'b']);
        $this->add($formId, ['enum' => 'ann', 'p1' => 'a1', 'p2' => 'b1']);
        $this->add($formId, ['enum' => 'ann', 'p1' => 'a2', 'p2' => 'b2']);
        $this->add($formId, ['enum' => 'ann', 'p1' => 'a3', 'p2' => 'b3']);
        $this->add($formId, ['enum' => 'ben', 'p1' => 'c1', 'p2' => 'd1']);
        $this->add($formId, ['enum' => 'ben', 'p1' => 'c2', 'p2' => 'd2']);
        $this->add($formId, ['enum' => 'ben', 'p1' => 'c3', 'p2' => 'd3']);
        $this->add($formId, ['enum' => 'cid', 'p1' => 'x', 'p2' => 'y']);
        $this->add($formId, ['enum' => 'cid', 'p1' => 'x', 'p2' => 'z']);
        $this->add($formId, ['enum' => 'cid', 'p1' => 'q', 'p2' => 'w']);

        $r = $this->compute($formId, 3);
        $this->assertTrue($r['enabled']);
        $this->assertSame(4, $r['scored']);
        $this->assertSame(0, $r['insufficient']);
        $this->assertTrue($r['signals'][0]['active']); // percentmatch se activó

        // fab es el peor y el primero listado; su índice supera el umbral de sospecha.
        $top = $r['teams'][0]['enumerators'][0];
        $this->assertSame('fab', $top['name']);
        $this->assertSame('high', $top['components'][0]['level']);
        $this->assertGreaterThanOrEqual(Risk::SUSPICION_Z, $top['index']);

        // Valores brutos de percentmatch exactos.
        $this->assertSame(1.0, $this->component($this->enumByName($r, 'fab'), 'percentmatch')['value']);
        $this->assertSame(0.0, $this->component($this->enumByName($r, 'ann'), 'percentmatch')['value']);

        // ann (todo distinto) no es sospechoso: z negativo → índice 0.
        $ann = $this->enumByName($r, 'ann');
        $this->assertSame(0.0, $ann['index']);
        $this->assertSame('low', $this->component($ann, 'percentmatch')['level']);

        // El equipo «alberga» exactamente un sospechoso, y es fab.
        $team = $r['teams'][0];
        $this->assertSame(1, $team['harbors']['over_threshold']);
        $this->assertSame('fab', $team['harbors']['worst_name']);
        $this->assertSame($team['harbors']['worst_index'], $team['index']);
    }

    public function testMinNGate(): void
    {
        $formId = $this->makeForm();
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'fab', 'p1' => 'a', 'p2' => 'b']);
        $this->add($formId, ['enum' => 'low', 'p1' => 'a', 'p2' => 'b']); // solo 2 → insuficiente
        $this->add($formId, ['enum' => 'low', 'p1' => 'a', 'p2' => 'b']);

        $r = $this->compute($formId, 3);
        $this->assertSame(1, $r['scored']);
        $this->assertSame(1, $r['insufficient']);

        $low = $this->enumByName($r, 'low');
        $this->assertTrue($low['insufficient']);
        $this->assertNull($low['index']);
        $this->assertSame([], $low['components']);
        $this->assertSame(2, $low['count']);
    }

    public function testPercentMatchNeedsRepeatedContent(): void
    {
        $formId = $this->makeForm();
        // Tres envíos SIN contenido (solo el campo del encuestador): no hay firma.
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'solo']);

        $r = $this->compute($formId, 3);
        $this->assertFalse($r['signals'][0]['active']);
        $this->assertSame('no_repeated_content', $r['signals'][0]['reason']);

        $solo = $this->enumByName($r, 'solo');
        $this->assertFalse($solo['insufficient']);          // puntuable, pero sin percentmatch
        $this->assertNull($this->component($solo, 'percentmatch'));
        $this->assertSame(0.0, $solo['index']);             // ninguna señal se desvía de los pares
    }

    public function testFieldScopeExcludesHiddenFieldFromSignature(): void
    {
        $formId = $this->makeForm();
        // p1/p2 idénticos; solo difiere `secret`. Con `secret` visible: 2/3 de 3 campos.
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'x', 'p1' => 'a', 'p2' => 'b', 'secret' => "s$i"]);

        $visible = Risk::compute($formId, null, null, null, 'es', null, 'enum', 3);
        $this->assertEqualsWithDelta(
            0.6667, $this->component($this->enumByName($visible, 'x'), 'percentmatch')['value'], 0.0005);

        // Con `secret` oculto, los tres envíos son idénticos en contenido → 1.0.
        $fieldScope = FieldScope::normalize(['hidden' => ['secret']]);
        $hidden = Risk::compute($formId, null, null, $fieldScope, 'es', null, 'enum', 3);
        $this->assertSame(1.0, $this->component($this->enumByName($hidden, 'x'), 'percentmatch')['value']);
    }

    public function testSamplingCapReported(): void
    {
        $formId = $this->makeForm();
        $n = Risk::PM_SAMPLE + 1;
        for ($i = 0; $i < $n; $i++) $this->add($formId, ['enum' => 'big', 'p1' => 'a', 'p2' => 'b']);

        $r = $this->compute($formId, 3);
        $pm = $this->component($this->enumByName($r, 'big'), 'percentmatch');
        $this->assertTrue($pm['sampled']);
        $this->assertSame(Risk::PM_SAMPLE, $pm['sample_size']);
        $this->assertSame(1.0, $pm['value']);
    }

    public function testStatusMixCountsAllReceived(): void
    {
        $formId = $this->makeForm();
        $admin  = $this->makeUser('admin');
        $pend = $this->add($formId, ['enum' => 'ana', 'p1' => 'a', 'p2' => 'b']);
        $held = $this->add($formId, ['enum' => 'ana', 'p1' => 'a', 'p2' => 'b']);
        $appr = $this->add($formId, ['enum' => 'ana', 'p1' => 'a', 'p2' => 'b']);
        $rej  = $this->add($formId, ['enum' => 'ana', 'p1' => 'a', 'p2' => 'b']);
        ValidationStatus::recordReview($held, $admin, 'app', 'on_hold');
        ValidationStatus::recordReview($appr, $admin, 'app', 'approved');
        ValidationStatus::recordReview($rej, $admin, 'app', 'rejected');

        $r = $this->compute($formId, 1, null, ['pending', 'on_hold']);
        $ana = $this->enumByName($r, 'ana');
        // La mezcla de estado cuenta TODOS los recibidos (el compañero «resumen de estado»).
        $this->assertSame(['pending' => 1, 'approved' => 1, 'on_hold' => 1, 'rejected' => 1], $ana['status']);
        $this->assertSame(4, $ana['received']);
        // Pero el índice solo se alimenta de lo que está en alcance (pendiente + en espera).
        $this->assertSame(2, $ana['count']);
        // El equipo agrega la misma mezcla.
        $this->assertSame(['pending' => 1, 'approved' => 1, 'on_hold' => 1, 'rejected' => 1], $r['teams'][0]['status']);
    }

    public function testTeamGroupingAndHarbors(): void
    {
        $formId = $this->makeForm();
        // Equipo norte alberga al fabricante entre pares honestos (hacen falta varios
        // pares para que su z destaque: con pocos, la MAD los diluye). Sur es limpio.
        foreach ([1, 2, 3] as $i) $this->add($formId, ['team' => 'norte', 'enum' => 'fab', 'p1' => 'a', 'p2' => 'b']);
        foreach ([1, 2, 3] as $i) $this->add($formId, ['team' => 'norte', 'enum' => 'ann', 'p1' => "a$i", 'p2' => "b$i"]);
        foreach ([1, 2, 3] as $i) $this->add($formId, ['team' => 'norte', 'enum' => 'cid', 'p1' => "e$i", 'p2' => "f$i"]);
        $this->add($formId, ['team' => 'norte', 'enum' => 'dee', 'p1' => 'x', 'p2' => 'y']);
        $this->add($formId, ['team' => 'norte', 'enum' => 'dee', 'p1' => 'x', 'p2' => 'z']);
        $this->add($formId, ['team' => 'norte', 'enum' => 'dee', 'p1' => 'q', 'p2' => 'w']);
        foreach ([1, 2, 3] as $i) $this->add($formId, ['team' => 'sur', 'enum' => 'ben', 'p1' => "c$i", 'p2' => "d$i"]);

        $r = Risk::compute($formId, null, null, null, 'es', 'team', 'enum', 3);
        $this->assertSame('team', $r['team_field']['key']);
        $this->assertCount(2, $r['teams']);
        // norte (alberga sospechoso) va antes que sur.
        $this->assertSame('norte', $r['teams'][0]['name']);
        $this->assertSame(1, $r['teams'][0]['harbors']['over_threshold']);
        $this->assertSame('fab', $r['teams'][0]['harbors']['worst_name']);
        $this->assertSame(0, $r['teams'][1]['harbors']['over_threshold']);
    }

    // ---- Resto de señales (Fase 1) ----

    public function testStraightLining(): void
    {
        $formId = $this->makeForm();
        $schema = $this->schema(['q1' => 'select_one l', 'q2' => 'select_one l', 'q3' => 'select_one l', 'note' => 'text']);
        // flat: misma opción en las 3 select_one → straight-lining 1.0 (note varía → no es
        // duplicado perfecto). mix: opciones distintas → 1/3.
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'flat', 'q1' => 'a', 'q2' => 'a', 'q3' => 'a', 'note' => "n$i"]);
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'mix', 'q1' => 'a', 'q2' => 'b', 'q3' => 'c', 'note' => "n$i"]);

        $r = $this->computeS($formId, $schema, 3);
        $this->assertSame(1.0, $this->component($this->enumByName($r, 'flat'), 'straightlining')['value']);
        $this->assertEqualsWithDelta(0.3333, $this->component($this->enumByName($r, 'mix'), 'straightlining')['value'], 0.0005);
    }

    public function testDistributionVsPool(): void
    {
        $formId = $this->makeForm();
        $schema = $this->schema(['q' => 'select_one l', 'note' => 'text']);
        // Pool dominado por 'b' (20 de crowd). El outlier siempre responde 'a'.
        foreach (range(1, 20) as $i) $this->add($formId, ['enum' => 'crowd', 'q' => 'b', 'note' => "c$i"]);
        foreach (range(1, 5) as $i)  $this->add($formId, ['enum' => 'out',   'q' => 'a', 'note' => "o$i"]);

        $r = $this->computeS($formId, $schema, 5);
        // Pool q: a=5/25=0.2, b=20/25=0.8. out(a=1.0) TVD=0.8; crowd(b=1.0) TVD=0.2.
        $this->assertEqualsWithDelta(0.8, $this->component($this->enumByName($r, 'out'), 'distribution')['value'], 0.0005);
        $this->assertEqualsWithDelta(0.2, $this->component($this->enumByName($r, 'crowd'), 'distribution')['value'], 0.0005);
    }

    public function testBenfordGateAndValue(): void
    {
        $formId = $this->makeForm();
        $schema = $this->schema(['n' => 'integer', 'note' => 'text']);
        // bad: 30 numéricos que empiezan por 5 → muy lejos de Benford.
        foreach (range(1, 30) as $i) $this->add($formId, ['enum' => 'bad', 'n' => 5, 'note' => "b$i"]);
        // few: solo 10 numéricos → por debajo del gate (BENFORD_MIN = 30).
        foreach (range(1, 10) as $i) $this->add($formId, ['enum' => 'few', 'n' => $i, 'note' => "f$i"]);

        $r = $this->computeS($formId, $schema, 10);
        $this->assertEqualsWithDelta(0.921, $this->component($this->enumByName($r, 'bad'), 'benford')['value'], 0.001);
        $this->assertNull($this->component($this->enumByName($r, 'few'), 'benford'));
    }

    public function testProductivity(): void
    {
        $formId = $this->makeForm();
        // fast: 6 envíos el mismo día → 6/día. slow: 3 envíos en 3 días → 1/día.
        foreach (range(1, 6) as $i) $this->addAt($formId, ['enum' => 'fast', 'p1' => "x$i"], '2026-07-01 09:00:00');
        $this->addAt($formId, ['enum' => 'slow', 'p1' => 'a'], '2026-07-01 09:00:00');
        $this->addAt($formId, ['enum' => 'slow', 'p1' => 'b'], '2026-07-02 09:00:00');
        $this->addAt($formId, ['enum' => 'slow', 'p1' => 'c'], '2026-07-03 09:00:00');

        $r = $this->compute($formId, 3);
        $this->assertSame(6.0, $this->component($this->enumByName($r, 'fast'), 'productivity')['value']);
        $this->assertSame(1.0, $this->component($this->enumByName($r, 'slow'), 'productivity')['value']);
    }

    public function testGpsClustering(): void
    {
        $formId = $this->makeForm();
        // clust: 5 envíos EN EL MISMO punto → dispersión 0 (sospechoso). spread: repartidos.
        foreach (range(1, 5) as $i) $this->add($formId, ['enum' => 'clust', 'p' => "x$i", '_geolocation' => [23.11, -82.36]]);
        $pts = [[23.10, -82.30], [23.20, -82.40], [23.30, -82.20], [23.05, -82.50], [23.40, -82.10]];
        foreach ($pts as $i => $pt) $this->add($formId, ['enum' => 'spread', 'p' => "y$i", '_geolocation' => $pt]);

        $r = $this->compute($formId, 5);
        $clust = $this->enumByName($r, 'clust');
        $this->assertSame(0.0, $this->component($clust, 'gps_cluster')['value']);
        // Puntos apiñados = más sospechoso que los repartidos.
        $this->assertGreaterThan($this->enumByName($r, 'spread')['index'], $clust['index']);
    }

    public function testSkipRateIncludingDontKnow(): void
    {
        $formId = $this->makeForm();
        $schema = $this->schema(['q1' => 'text', 'q2' => 'text', 'q3' => 'text']);
        // blank: responde solo 1 de 3 → 2/3 en blanco. dk: responde 3 pero 2 son «no sabe».
        // full: responde las 3 → 0.
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'blank', 'q1' => "x$i"]);
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'dk', 'q1' => "x$i", 'q2' => 'no sabe', 'q3' => '99']);
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'full', 'q1' => "x$i", 'q2' => "y$i", 'q3' => "z$i"]);

        $r = $this->computeS($formId, $schema, 3);
        $this->assertEqualsWithDelta(0.6667, $this->component($this->enumByName($r, 'blank'), 'skip_rate')['value'], 0.0005);
        $this->assertEqualsWithDelta(0.6667, $this->component($this->enumByName($r, 'dk'), 'skip_rate')['value'], 0.0005);
        $this->assertSame(0.0, $this->component($this->enumByName($r, 'full'), 'skip_rate')['value']);
    }

    public function testTeamDistributionVsPool(): void
    {
        $formId = $this->makeForm();
        $schema = $this->schema(['q' => 'select_one l', 'note' => 'text']);
        // Equipo raro: siempre 'a'. Equipos normales: sobre todo 'b' pero con dispersión
        // entre ellos (n2 mixto) para que la mediana/MAD entre equipos no sea degenerada.
        foreach (range(1, 12) as $i) $this->add($formId, ['team' => 'raro', 'enum' => "r$i", 'q' => 'a', 'note' => "r$i"]);
        foreach (range(1, 12) as $i) $this->add($formId, ['team' => 'n1', 'enum' => "a$i", 'q' => 'b', 'note' => "a$i"]);
        foreach (range(1, 12) as $i) $this->add($formId, ['team' => 'n2', 'enum' => "b$i", 'q' => $i <= 4 ? 'a' : 'b', 'note' => "b$i"]);
        foreach (range(1, 12) as $i) $this->add($formId, ['team' => 'n3', 'enum' => "c$i", 'q' => 'b', 'note' => "c$i"]);

        $r = Risk::compute($formId, $schema, null, null, 'es', 'team', 'enum', 1);
        $teamComp = function (array $risk, string $name): ?array {
            foreach ($risk['teams'] as $t) {
                if ($t['name'] === $name) {
                    foreach ($t['components'] as $c) if ($c['key'] === 'team_distribution') return $c;
                }
            }
            return null;
        };
        // Pool q: a=12/36=0.333, b=24/36=0.667. raro(a=1.0) TVD=0.667; n1/n2(b=1.0) TVD=0.333.
        $this->assertEqualsWithDelta(0.6667, $teamComp($r, 'raro')['value'], 0.0005);
        $this->assertEqualsWithDelta(0.3333, $teamComp($r, 'n1')['value'], 0.0005);
        // El equipo raro se desvía por encima de la mediana entre equipos → z positivo.
        $this->assertGreaterThan(0, $teamComp($r, 'raro')['z']);
    }

    public function testRowScopeRestricts(): void
    {
        $formId = $this->makeForm();
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'fab', 'region' => 'norte', 'p1' => 'a', 'p2' => 'b']);
        foreach ([1, 2, 3] as $i) $this->add($formId, ['enum' => 'out', 'region' => 'sur', 'p1' => 'a', 'p2' => 'b']);

        $scope = RowScope::normalize(['conditions' => [['field' => 'region', 'values' => ['norte']]]]);
        $r = $this->compute($formId, 3, $scope);
        $this->assertSame(3, $r['received']);
        $this->assertNull($this->enumByName($r, 'out'));
        $this->assertNotNull($this->enumByName($r, 'fab'));
    }

    // ---- Métrica qc_flag_rate (tasa de banderas del QC, vía Quality::riskRates) ----

    /** Envío con encuestador y tiempos (mismo día), para que lib/Quality lo evalúe. */
    private function addTimed(int $formId, string $enum, string $start, string $end): string
    {
        return $this->add($formId, [
            'enum'  => $enum,
            'start' => "2026-07-01T$start",
            'end'   => "2026-07-01T$end",
        ]);
    }

    public function testQcFlagRateFromQualityCounts(): void
    {
        $formId = $this->makeForm();
        // fab: 2 de 3 cortas (min 4). cid: 1 de 3. ann/ben: limpios. Sin solapes.
        $this->addTimed($formId, 'fab', '09:00:00', '09:02:00');
        $this->addTimed($formId, 'fab', '10:00:00', '10:02:00');
        $this->addTimed($formId, 'fab', '11:00:00', '11:30:00');
        $this->addTimed($formId, 'cid', '09:00:00', '09:02:00');
        $this->addTimed($formId, 'cid', '10:00:00', '10:30:00');
        $this->addTimed($formId, 'cid', '11:00:00', '11:30:00');
        foreach (['ann', 'ben'] as $who) {
            $this->addTimed($formId, $who, '09:00:00', '09:30:00');
            $this->addTimed($formId, $who, '10:00:00', '10:30:00');
            $this->addTimed($formId, $who, '11:00:00', '11:30:00');
        }

        // La tasa NO se recomputa en Risk: viene de lib/Quality (misma fuente que el QC).
        $quality = Quality::compute($formId, null, null, null, 'es', null, 'enum', 4, null, null);
        $r = Risk::compute($formId, null, null, null, 'es', null, 'enum', 3, null, Quality::riskRates($quality));

        $fab = $this->enumByName($r, 'fab');
        $c = $this->component($fab, 'qc_flag_rate');
        $this->assertNotNull($c);
        $this->assertSame(0.6667, $c['value']);
        $this->assertSame(2, $c['flagged']);
        $this->assertSame(3, $c['submissions']);
        // Pares: 0.6667 / 0.3333 / 0 / 0 → fab destaca claramente por encima (dir high).
        $this->assertGreaterThan(1.5, $c['z']);
        // Limpio: tasa 0, z no positivo.
        $ann = $this->component($this->enumByName($r, 'ann'), 'qc_flag_rate');
        $this->assertSame(0.0, $ann['value']);
        $this->assertLessThanOrEqual(0.0, $ann['z']);
        // La señal figura activa en el registro de señales.
        $sig = array_values(array_filter($r['signals'], fn($s) => $s['key'] === 'qc_flag_rate'))[0];
        $this->assertTrue($sig['active']);
    }

    /** Sin $qcRates (llamador que no computó el QC) la métrica queda inactiva. */
    public function testQcFlagRateAbsentWithoutRates(): void
    {
        $formId = $this->makeForm();
        foreach ([1, 2, 3] as $i) $this->addTimed($formId, 'ana', "0$i:00:00", "0$i:30:00");

        $r = $this->compute($formId, 3);
        $this->assertNull($this->component($this->enumByName($r, 'ana'), 'qc_flag_rate'));
        $sig = array_values(array_filter($r['signals'], fn($s) => $s['key'] === 'qc_flag_rate'))[0];
        $this->assertFalse($sig['active']);
        $this->assertSame('insufficient_data', $sig['reason']);
    }
}
