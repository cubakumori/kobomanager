<?php

declare(strict_types=1);

/**
 * Tests de lib/TeamConflicts (resolución de incongruencias equipo ↔ meta-equipo).
 * Cubren: detección de casos (envíos descarriados respecto al meta dominante de su
 * equipo), el desempate por ENCUESTADOR del modo 'approx' (incluida la normalización
 * y el alias de 1.46.0 sobre texto libre), los modos 'first'/'least', los casos sin
 * sugerencia (empate o meta sin equipos), y los grupos por meta para los modales.
 */
final class TeamConflictsTest extends DbTestCase
{
    /** Equipo y provincia como select_one; encuestador texto libre. */
    private function schema(): array
    {
        return [
            'fields' => [
                'team' => ['leaf' => 'team', 'type' => 'select_one teams', 'list' => 'teams', 'multi' => false, 'label' => ['' => 'Equipo']],
                'prov' => ['leaf' => 'prov', 'type' => 'select_one provs', 'list' => 'provs', 'multi' => false, 'label' => ['' => 'Provincia']],
                'enum' => ['leaf' => 'enum', 'type' => 'text', 'multi' => false, 'label' => ['' => 'Encuestador']],
            ],
            'choices' => [
                'teams' => ['t1' => ['' => 'Equipo Uno'], 't2' => ['' => 'Equipo Dos'], 't3' => ['' => 'Equipo Tres']],
                'provs' => ['p1' => ['' => 'Norte'], 'p2' => ['' => 'Sur']],
            ],
            'languages' => [null], 'meta_fields' => [], 'meta' => [],
        ];
    }

    /** Equipo y encuestador de TEXTO LIBRE (iniciales tecleadas); provincia select_one. */
    private function schemaFreeText(): array
    {
        return [
            'fields' => [
                'team' => ['leaf' => 'team', 'type' => 'text', 'multi' => false, 'label' => ['' => 'Equipo']],
                'prov' => ['leaf' => 'prov', 'type' => 'select_one provs', 'list' => 'provs', 'multi' => false, 'label' => ['' => 'Provincia']],
                'enum' => ['leaf' => 'enum', 'type' => 'text', 'multi' => false, 'label' => ['' => 'Encuestador']],
            ],
            'choices' => [
                'provs' => ['p1' => ['' => 'Norte'], 'p2' => ['' => 'Sur']],
            ],
            'languages' => [null], 'meta_fields' => [], 'meta' => [],
        ];
    }

    private function addSubmission(int $formId, array $payload, string $uid = ''): string
    {
        $uid = $uid !== '' ? $uid : 'uid_' . bin2hex(random_bytes(6));
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, ?)',
            [$formId, $uid, json_encode($payload), gmdate('Y-m-d H:i:s')]
        );
        return $uid;
    }

    /**
     * Semilla base: t1 y t2 resuelven a p1, t3 a p2; un envío de t3 apunta a p1
     * (descarriado). El encuestador AA trabaja en t1.
     */
    private function seedBase(int $formId): string
    {
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'AA']);
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'AA']);
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'BB']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p1', 'enum' => 'CC']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p1', 'enum' => 'CC']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        // El caso: equipo tecleado t3, pero meta p1 (el select fiable) y encuestador AA (de t1).
        return $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p1', 'enum' => 'AA'], 'uid_stray');
    }

    private function plan(int $formId, string $mode, ?array $schema = null): array
    {
        return TeamConflicts::plan(
            $formId, $schema ?? $this->schema(), null, null, 'es', 'team', 'enum', 'prov', $mode
        );
    }

    public function testDetectsStrayCaseWithDominantAndManualOption(): void
    {
        $formId = $this->makeForm();
        $this->seedBase($formId);

        $res = $this->plan($formId, 'confirm_each');

        // Un solo equipo en conflicto (t3, dominante p2) y un solo caso.
        $this->assertCount(1, $res['conflict_teams']);
        $this->assertSame('t3', $res['conflict_teams'][0]['team']['value']);
        $this->assertSame('p2', $res['conflict_teams'][0]['dominant']['value']);
        $this->assertSame(1, $res['conflict_teams'][0]['strays']);

        $this->assertCount(1, $res['cases']);
        $c = $res['cases'][0];
        $this->assertSame('uid_stray', $c['uid']);
        $this->assertSame('t3', $c['team']['value']);
        $this->assertSame('p1', $c['meta']['value']);   // el meta fiable del envío
        $this->assertSame('p2', $c['dominant']['value']); // opción manual «corregir el meta»
        $this->assertSame('AA', $c['enumerator']['value']);
        // En modo de confirmación el desempate por encuestador viaja igualmente
        // (preselección del modal), pero la UI no lo aplica sin elegirlo.
        $this->assertSame('t1', $c['suggestion']['value']);
        $this->assertSame('enumerator', $c['suggestion']['via']);
    }

    public function testApproxResolvesByEnumerator(): void
    {
        $formId = $this->makeForm();
        $this->seedBase($formId);

        $res = $this->plan($formId, 'approx');

        // AA solo trabaja (consistentemente) en t1 dentro de p1 → t1.
        $s = $res['cases'][0]['suggestion'];
        $this->assertNotNull($s);
        $this->assertSame('t1', $s['value']);
        $this->assertSame('enumerator', $s['via']);
    }

    public function testApproxUnresolvedWhenEnumeratorUnknownOrTied(): void
    {
        $formId = $this->makeForm();
        // Como seedBase pero el descarriado lo firma ZZ (nadie lo conoce en p1).
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'AA']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p1', 'enum' => 'CC']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p1', 'enum' => 'ZZ']);
        // Y otro firmado por EE, que trabaja en t1 Y t2 a partes iguales (empate).
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'EE']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p1', 'enum' => 'EE']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p1', 'enum' => 'EE']);

        $res = $this->plan($formId, 'approx');

        $this->assertCount(2, $res['cases']);
        foreach ($res['cases'] as $c) {
            $this->assertNull($c['suggestion'], "El caso {$c['enumerator']['value']} no debe adivinar");
        }
    }

    public function testStraysDoNotTeachEnumeratorMembership(): void
    {
        $formId = $this->makeForm();
        // AA trabaja en t1 (p1). t3 tiene DOS descarriados hacia p1 firmados por AA:
        // esos envíos llevan el equipo MAL tecleado y no deben contar como que AA
        // «pertenece a t3» (t3 ni siquiera es candidato de p1: su dominante es p2).
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'AA']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p1', 'enum' => 'AA']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p1', 'enum' => 'AA']);

        $res = $this->plan($formId, 'approx');

        $this->assertCount(2, $res['cases']);
        foreach ($res['cases'] as $c) {
            $this->assertSame('t1', $c['suggestion']['value']);
        }
    }

    public function testFirstAndLeastModes(): void
    {
        $formId = $this->makeForm();
        $this->seedBase($formId);

        // 'first': primer equipo del meta correcto por etiqueta («Equipo Dos» < «Equipo Uno»).
        $first = $this->plan($formId, 'first');
        $this->assertSame('t2', $first['cases'][0]['suggestion']['value']);
        $this->assertSame('first', $first['cases'][0]['suggestion']['via']);

        // 'least': el equipo con menos encuestas de p1 (t2 tiene 2, t1 tiene 3).
        $least = $this->plan($formId, 'least');
        $this->assertSame('t2', $least['cases'][0]['suggestion']['value']);
        $this->assertSame('least', $least['cases'][0]['suggestion']['via']);
    }

    public function testNoCandidatesLeavesCaseUnresolved(): void
    {
        $formId = $this->makeForm();
        // El meta del descarriado (p2) no tiene NINGÚN equipo que resuelva a él:
        // ni 'first' ni 'least' pueden proponer nada (queda el arreglo manual del meta).
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'AA']);
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'AA']);
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p2', 'enum' => 'AA']);

        foreach (['approx', 'first', 'least'] as $mode) {
            $res = $this->plan($formId, $mode);
            $this->assertCount(1, $res['cases'], "modo $mode");
            $this->assertNull($res['cases'][0]['suggestion'], "modo $mode");
            $this->assertSame([], $res['meta_groups'][0]['teams'], "modo $mode");
        }
    }

    public function testMetaGroupsForConfirmationModals(): void
    {
        $formId = $this->makeForm();
        $this->seedBase($formId);

        $res = $this->plan($formId, 'confirm_group');

        $this->assertCount(1, $res['meta_groups']);
        $g = $res['meta_groups'][0];
        $this->assertSame('p1', $g['value']);
        $this->assertSame(1, $g['cases']);
        // Candidatos: los equipos que resuelven a p1, con su volumen (para 'least' visual).
        $this->assertEqualsCanonicalizing(
            [['t1', 3], ['t2', 2]],
            array_map(fn($t) => [$t['value'], $t['count']], $g['teams'])
        );
    }

    public function testFreeTextNormalizationMergesSpellingsAndWritesCanon(): void
    {
        $formId = $this->makeForm();
        // Equipo texto libre: «ABC» (2) y «a.b.c» (1) son el MISMO equipo (clave plegada),
        // así que NO hay conflicto entre grafías; el conflicto real es de XY hacia p1.
        // El encuestador del descarriado firma «j.l.» y en el equipo bueno firma «JL».
        $this->addSubmission($formId, ['team' => 'ABC', 'prov' => 'p1', 'enum' => 'JL']);
        $this->addSubmission($formId, ['team' => 'ABC', 'prov' => 'p1', 'enum' => 'JL']);
        $this->addSubmission($formId, ['team' => 'a.b.c', 'prov' => 'p1', 'enum' => 'MM']);
        $this->addSubmission($formId, ['team' => 'XY', 'prov' => 'p2', 'enum' => 'NN']);
        $this->addSubmission($formId, ['team' => 'XY', 'prov' => 'p2', 'enum' => 'NN']);
        $this->addSubmission($formId, ['team' => 'XY', 'prov' => 'p1', 'enum' => 'j.l.'], 'uid_stray2');

        $res = $this->plan($formId, 'approx', $this->schemaFreeText());

        // Un solo conflicto (XY) — las grafías de ABC no cuentan como equipos distintos.
        $this->assertCount(1, $res['conflict_teams']);
        $this->assertSame('XY', $res['conflict_teams'][0]['team']['value']);
        $c = $res['cases'][0];
        $this->assertSame('uid_stray2', $c['uid']);
        // El desempate une «j.l.» con «JL» (normalización) y el valor de ESCRITURA es
        // la grafía canónica más frecuente del equipo destino («ABC», no «a.b.c»).
        $this->assertNotNull($c['suggestion']);
        $this->assertSame('ABC', $c['suggestion']['value']);
    }

    public function testAliasUnitesEnumeratorSpellingsAcrossKeys(): void
    {
        $formId = $this->makeForm();
        DB::run('UPDATE forms SET member_normalize = ? WHERE id = ?', ['alias', $formId]);
        // «jlvh» y «JLHV» tienen claves plegadas DISTINTAS: solo el alias las une.
        DB::run(
            'INSERT INTO member_aliases (form_id, axis, from_key, to_value) VALUES (?, ?, ?, ?)',
            [$formId, 'member', 'jlvh', 'JLHV']
        );
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'JLHV']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p1', 'enum' => 'CC']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'enum' => 'DD']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p1', 'enum' => 'jlvh'], 'uid_stray3');

        $res = $this->plan($formId, 'approx');

        $this->assertCount(1, $res['cases']);
        $this->assertSame('t1', $res['cases'][0]['suggestion']['value']);
    }

    public function testRowsWithoutTeamOrMetaAreNotCases(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'enum' => 'AA']);
        $this->addSubmission($formId, ['prov' => 'p2', 'enum' => 'AA']);   // sin equipo
        $this->addSubmission($formId, ['team' => 't1', 'enum' => 'AA']);   // sin meta («sin agrupar»)

        $res = $this->plan($formId, 'approx');

        $this->assertSame([], $res['cases']);
        $this->assertSame([], $res['conflict_teams']);
    }

    public function testModeFallsBackToApprox(): void
    {
        $this->assertSame('approx', TeamConflicts::mode(null));
        $this->assertSame('approx', TeamConflicts::mode('nonsense'));
        $this->assertSame('confirm_each', TeamConflicts::mode('confirm_each'));
    }
}
