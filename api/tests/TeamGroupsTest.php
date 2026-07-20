<?php

declare(strict_types=1);

/**
 * Tests de lib/TeamGroups (detección del meta-equipo). Cubren: el ranking por
 * dependencia funcional equipo → F de «Detectar meta-equipos», el listado de
 * conflictos y equipos sin valor de «Detectar problemas», y las respuestas de
 * «datos insuficientes» (informan, no bloquean).
 */
final class TeamGroupsTest extends DbTestCase
{
    /** Esquema con equipo (t1-t3), provincia (p1/p2, gruesa) y edad (a1/a2, ruido). */
    private function schema(): array
    {
        return [
            'fields' => [
                'team' => ['leaf' => 'team', 'type' => 'select_one teams', 'list' => 'teams', 'multi' => false, 'label' => ['' => 'Equipo']],
                'prov' => ['leaf' => 'prov', 'type' => 'select_one provs', 'list' => 'provs', 'multi' => false, 'label' => ['' => 'Provincia']],
                'age'  => ['leaf' => 'age',  'type' => 'select_one ages',  'list' => 'ages',  'multi' => false, 'label' => ['' => 'Edad']],
                'tags' => ['leaf' => 'tags', 'type' => 'select_multiple tg', 'list' => 'tg', 'multi' => true, 'label' => ['' => 'Etiquetas']],
            ],
            'choices' => [
                'teams' => ['t1' => ['' => 'Equipo Uno'], 't2' => ['' => 'Equipo Dos'], 't3' => ['' => 'Equipo Tres']],
                'provs' => ['p1' => ['' => 'Norte'], 'p2' => ['' => 'Sur']],
                'ages'  => ['a1' => ['' => '18-29'], 'a2' => ['' => '30-44']],
                'tg'    => ['x' => ['' => 'X']],
            ],
            'languages' => [null], 'meta_fields' => [], 'meta' => [],
        ];
    }

    private function addSubmission(int $formId, array $payload): void
    {
        DB::run(
            'INSERT INTO submissions_cache (form_id, submission_uid, json_payload, submitted_at)
             VALUES (?, ?, ?, ?)',
            [$formId, 'uid_' . bin2hex(random_bytes(6)), json_encode($payload), gmdate('Y-m-d H:i:s')]
        );
    }

    public function testSuggestRanksFunctionalDependencyAndCoarseness(): void
    {
        $formId = $this->makeForm();
        // prov: dependencia funcional perfecta (t1,t2 → p1; t3 → p2) y más gruesa
        // que el equipo (2 valores, 3 equipos). age: ruido (cada equipo mezcla).
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'age' => 'a1']);
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'age' => 'a2']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p1', 'age' => 'a1']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p1', 'age' => 'a2']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'age' => 'a1']);
        $this->addSubmission($formId, ['team' => 't3', 'prov' => 'p2', 'age' => 'a2']);

        $res = TeamGroups::suggest($formId, $this->schema(), null, null, 'es', 'team', null);

        $this->assertFalse($res['insufficient']);
        $this->assertSame(3, $res['teams']);
        // prov gana: consistente al 100 % y más gruesa que el equipo.
        $this->assertSame('prov', $res['candidates'][0]['field']);
        $this->assertSame('Provincia', $res['candidates'][0]['label']);
        $this->assertSame(100.0, $res['candidates'][0]['consistency_pct']);
        $this->assertTrue($res['candidates'][0]['coarser']);
        // age también aparece (el algoritmo rankea, no filtra), pero detrás y con
        // consistencia 0 (todos los equipos mezclan valores).
        $age = null;
        foreach ($res['candidates'] as $c) {
            if ($c['field'] === 'age') $age = $c;
        }
        $this->assertNotNull($age);
        $this->assertSame(0.0, $age['consistency_pct']);
        // Ni el equipo ni el select_multiple entran como candidatos.
        $this->assertNotContains('team', array_column($res['candidates'], 'field'));
        $this->assertNotContains('tags', array_column($res['candidates'], 'field'));
    }

    public function testSuggestExcludesEnumeratorAndHiddenFields(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1', 'age' => 'a1']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p2', 'age' => 'a1']);

        // age como campo de encuestador configurado → fuera; prov oculta → fuera.
        $fieldScope = FieldScope::normalize(['hidden' => ['prov']]);
        $res = TeamGroups::suggest($formId, $this->schema(), null, $fieldScope, 'es', 'team', 'age');

        $this->assertTrue($res['insufficient']);
        $this->assertSame('no_candidates', $res['reason']);
    }

    public function testSuggestInsufficientWithFewTeams(): void
    {
        $formId = $this->makeForm();
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1']);

        $res = TeamGroups::suggest($formId, $this->schema(), null, null, 'es', 'team', null);

        $this->assertTrue($res['insufficient']);
        $this->assertSame('not_enough_teams', $res['reason']);
        $this->assertSame(1, $res['teams']);
    }

    public function testCheckListsConflictsAndUnassigned(): void
    {
        $formId = $this->makeForm();
        // t1 consistente (p1); t2 repartido 2×p2 + 1×p1 → conflicto con dominante p2;
        // t3 con envíos pero SIN valor de prov → «sin asignar».
        $this->addSubmission($formId, ['team' => 't1', 'prov' => 'p1']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p2']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p2']);
        $this->addSubmission($formId, ['team' => 't2', 'prov' => 'p1']);
        $this->addSubmission($formId, ['team' => 't3']);

        $res = TeamGroups::check($formId, $this->schema(), null, null, 'es', 'team', 'prov');

        $this->assertFalse($res['insufficient']);
        $this->assertCount(1, $res['conflicts']);
        $c = $res['conflicts'][0];
        $this->assertSame('t2', $c['team']);
        $this->assertSame('p2', $c['dominant']);
        // Valores con el dominante primero y sus conteos.
        $this->assertSame([['p2', 2], ['p1', 1]], array_map(fn($v) => [$v['value'], $v['count']], $c['values']));
        $this->assertSame(['t3'], array_column($res['unassigned'], 'team'));
    }

    public function testCheckInsufficientWithoutData(): void
    {
        $formId = $this->makeForm();

        $res = TeamGroups::check($formId, $this->schema(), null, null, 'es', 'team', 'prov');

        $this->assertTrue($res['insufficient']);
        $this->assertSame('no_data', $res['reason']);
    }
}
