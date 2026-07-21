<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del panel de muestra en enlaces compartidos
 * (GET /public/share/{token}/sample + bandera `expose_sample`).
 * Es opt-in por enlace (OFF por defecto: el hecho/objetivo y el backlog revelan
 * recuentos agregados de revisión), exige campo de muestreo configurado en el
 * formulario, cuenta como vista para la regla «al menos una» y devuelve SOLO
 * agregados dentro del alcance por filas del enlace — nunca permisos internos
 * ni datos de envíos. El denominador es SIEMPRE el del plan (sin `?denominator=`).
 */
final class ShareSampleHttpTest extends HttpTestCase
{
    /**
     * Formulario con equipo `team`, muestreo `age` y plan (T1×young=2, T2×old=1).
     * Envíos: a1 (T1/young, aprobado), a2 (T1/young, pendiente), b1 (T2/old, aprobado).
     * Con denominador 'approved' (el default del plan): hecho=2, backlog pendiente=1.
     */
    private function seedSampleForm(bool $withSampleField = true): int
    {
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        DB::run('UPDATE forms SET stats_team_field = ? WHERE id = ?', ['team', $formId]);
        if ($withSampleField) {
            DB::run('UPDATE forms SET sample_field = ? WHERE id = ?', ['age', $formId]);
            DB::run(
                'INSERT INTO sample_targets (form_id, team_value, sample_value, target) VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
                [$formId, 'T1', 'young', 2, $formId, 'T2', 'old', 1]
            );
        }
        $this->seedSubmission($formId, 'a1', ['_id' => 1, 'team' => 'T1', 'age' => 'young']);
        $this->seedSubmission($formId, 'a2', ['_id' => 2, 'team' => 'T1', 'age' => 'young']);
        $this->seedSubmission($formId, 'b1', ['_id' => 3, 'team' => 'T2', 'age' => 'old']);
        DB::run("UPDATE submissions_cache SET review_status = 'approved' WHERE submission_uid IN ('a1', 'b1')");
        return $formId;
    }

    private function adminJar(): string
    {
        $this->seedUser('admin', 'admin@test.local', 'secret123');
        return $this->login('admin@test.local', 'secret123');
    }

    /** Crea un enlace vía el endpoint admin y devuelve {id, token}. */
    private function createLink(int $formId, array $extra, string $jar): array
    {
        $res = $this->request('POST', 'admin/shares', array_merge([
            'form_id'     => $formId,
            'expose_list' => true,
        ], $extra), $jar);
        $this->assertSame(201, $res['status'], 'creación de enlace falló: ' . $res['raw']);
        return $res['json']['data'];
    }

    /** Índice {claveEquipo: fila} de `teams[]` del payload del panel. */
    private function teams(array $payload): array
    {
        $byKey = [];
        foreach ($payload['teams'] as $tm) {
            $byKey[$tm['key']] = $tm;
        }
        return $byKey;
    }

    public function testFlagOffHidesEndpointAndMeta(): void
    {
        $formId = $this->seedSampleForm();
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, [], $jar);

        $meta = $this->request('GET', "public/share/{$link['token']}");
        $this->assertSame(200, $meta['status']);
        $this->assertFalse($meta['json']['data']['expose_sample']);

        $res = $this->request('GET', "public/share/{$link['token']}/sample");
        $this->assertSame(404, $res['status']);
        @unlink($jar);
    }

    public function testFlagExposesAggregatePanelOnly(): void
    {
        $formId = $this->seedSampleForm();
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, ['expose_sample' => true], $jar);

        $meta = $this->request('GET', "public/share/{$link['token']}");
        $this->assertTrue($meta['json']['data']['expose_sample']);

        $res = $this->request('GET', "public/share/{$link['token']}/sample");
        $this->assertSame(200, $res['status'], $res['raw']);
        $data = $res['json']['data'];

        // Nada de la vista interna que no toque: ni permisos ni datos de envíos.
        $this->assertArrayNotHasKey('can_settings', $data);
        $this->assertArrayNotHasKey('can_sample', $data);

        // Denominador del plan ('approved'): hecho=2/3, y el pendiente va al backlog.
        $this->assertSame('approved', $data['denominator']);
        $this->assertSame(2, $data['grand']['done']);
        $this->assertSame(3, $data['grand']['target']);
        $this->assertSame(1, $data['grand']['pending']);
        $teams = $this->teams($data);
        $this->assertSame(1, $teams['T1']['done']);
        $this->assertSame(2, $teams['T1']['target']);
        $this->assertSame(1, $teams['T1']['pending']);
        $this->assertSame(1, $teams['T2']['done']);

        // El toggle transitorio del denominador es interno: aquí se IGNORA.
        $res2 = $this->request('GET', "public/share/{$link['token']}/sample?denominator=approved_pending");
        $this->assertSame(200, $res2['status']);
        $this->assertSame('approved', $res2['json']['data']['denominator']);
        $this->assertSame(2, $res2['json']['data']['grand']['done']);
        @unlink($jar);
    }

    public function testFlagIgnoredWithoutSampleField(): void
    {
        // Sin campo de muestreo la bandera se fuerza a 0 al crear…
        $formId = $this->seedSampleForm(false);
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, ['expose_sample' => true], $jar);

        $list = $this->request('GET', 'admin/shares', null, $jar);
        $item = array_values(array_filter($list['json']['data']['items'], fn($l) => $l['id'] === $link['id']))[0];
        $this->assertFalse($item['expose_sample']);

        // …y el endpoint público no existe para el enlace.
        $res = $this->request('GET', "public/share/{$link['token']}/sample");
        $this->assertSame(404, $res['status']);
        @unlink($jar);
    }

    public function testSampleOnlyLinkIsAValidView(): void
    {
        // Un enlace SOLO-muestra (el coordinador sigue el avance sin ver envíos)
        // satisface la regla «al menos una vista»…
        $formId = $this->seedSampleForm();
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, [
            'expose_list'   => false,
            'expose_detail' => false,
            'expose_sample' => true,
        ], $jar);

        $this->assertSame(200, $this->request('GET', "public/share/{$link['token']}/sample")['status']);
        $this->assertSame(404, $this->request('GET', "public/share/{$link['token']}/submissions")['status']);

        // …pero sin muestra configurada, «solo muestra» no deja ninguna vista → error.
        // (Formulario aparte sin sample_field; no hacen falta envíos.)
        $formId2 = $this->seedForm($this->seedAccount(), 'aSampleless');
        $res = $this->request('POST', 'admin/shares', [
            'form_id'       => $formId2,
            'expose_list'   => false,
            'expose_detail' => false,
            'expose_sample' => true,
        ], $jar);
        $this->assertSame(422, $res['status']);
        @unlink($jar);
    }

    public function testRowFilterScopesThePanel(): void
    {
        $formId = $this->seedSampleForm();
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, [
            'expose_sample' => true,
            'row_filter' => ['match' => 'all', 'groups' => [
                ['match' => 'all', 'conditions' => [['field' => 'team', 'op' => 'in', 'values' => ['T1']]]],
            ]],
        ], $jar);

        $res = $this->request('GET', "public/share/{$link['token']}/sample");
        $this->assertSame(200, $res['status']);
        $teams = $this->teams($res['json']['data']);
        // T1 visible (con sus datos); T2 sigue en el eje por su fila del PLAN, pero
        // sin nada hecho ni backlog (sus envíos quedan fuera del alcance).
        $this->assertSame(1, $teams['T1']['done']);
        $this->assertSame(0, $teams['T2']['done']);
        $this->assertSame(0, $teams['T2']['pending']);
        $this->assertSame(1, $res['json']['data']['grand']['done']);
        @unlink($jar);
    }
}
