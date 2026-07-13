<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP del resumen de revisión en enlaces compartidos
 * (GET /public/share/{token}/review-summary + bandera `expose_review_summary`).
 * Es opt-in por enlace (OFF por defecto: la vista pública oculta la revisión a
 * propósito), exige campo de equipo/encuestador en el formulario y devuelve SOLO
 * recuentos agregados dentro del alcance por filas del enlace.
 */
final class ShareReviewSummaryHttpTest extends HttpTestCase
{
    /** Forma con encuestador `enum` y 3 envíos: ana (1 aprobado + 1 pendiente), bea (1 rechazado). */
    private function seedReviewForm(bool $withEnumField = true): int
    {
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        if ($withEnumField) {
            DB::run('UPDATE forms SET stats_enumerator_field = ? WHERE id = ?', ['enum', $formId]);
        }
        $this->seedSubmission($formId, 'a1', ['_id' => 1, 'enum' => 'ana']);
        $this->seedSubmission($formId, 'a2', ['_id' => 2, 'enum' => 'ana']);
        $this->seedSubmission($formId, 'b1', ['_id' => 3, 'enum' => 'bea']);
        DB::run("UPDATE submissions_cache SET review_status = 'approved' WHERE submission_uid = 'a1'");
        DB::run("UPDATE submissions_cache SET review_status = 'rejected' WHERE submission_uid = 'b1'");
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

    /** Índice {nombreEncuestador: fila} del único grupo del resumen. */
    private function enumerators(array $payload): array
    {
        $this->assertNotEmpty($payload['review_summary']);
        $byName = [];
        foreach ($payload['review_summary'][0]['enumerators'] as $e) {
            $byName[$e['name']] = $e;
        }
        return $byName;
    }

    public function testFlagOffHidesEndpointAndMeta(): void
    {
        $formId = $this->seedReviewForm();
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, [], $jar);

        $meta = $this->request('GET', "public/share/{$link['token']}");
        $this->assertSame(200, $meta['status']);
        $this->assertFalse($meta['json']['data']['expose_review_summary']);

        $res = $this->request('GET', "public/share/{$link['token']}/review-summary");
        $this->assertSame(404, $res['status']);
        @unlink($jar);
    }

    public function testFlagExposesAggregateCountsOnly(): void
    {
        $formId = $this->seedReviewForm();
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, ['expose_review_summary' => true], $jar);

        $meta = $this->request('GET', "public/share/{$link['token']}");
        $this->assertTrue($meta['json']['data']['expose_review_summary']);

        $res = $this->request('GET', "public/share/{$link['token']}/review-summary");
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'];

        // Solo el resumen: nada del resto del análisis de calidad ni datos de envíos.
        $this->assertArrayNotHasKey('teams', $data);
        $this->assertArrayNotHasKey('flags', $data);
        $this->assertArrayNotHasKey('admissible_pending', $data);

        $enums = $this->enumerators($data);
        $this->assertSame(2, $enums['ana']['total']);
        $this->assertSame(1, $enums['ana']['status']['approved']);
        $this->assertSame(1, $enums['ana']['status']['pending']);
        $this->assertSame(1, $enums['bea']['status']['rejected']);
        @unlink($jar);
    }

    public function testFlagIgnoredWithoutGroupingFields(): void
    {
        // Sin campo de equipo/encuestador la bandera se fuerza a 0 al crear…
        $formId = $this->seedReviewForm(false);
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, ['expose_review_summary' => true], $jar);

        $list = $this->request('GET', 'admin/shares', null, $jar);
        $item = array_values(array_filter($list['json']['data']['items'], fn($l) => $l['id'] === $link['id']))[0];
        $this->assertFalse($item['expose_review_summary']);

        // …y el endpoint público no existe para el enlace.
        $res = $this->request('GET', "public/share/{$link['token']}/review-summary");
        $this->assertSame(404, $res['status']);
        @unlink($jar);
    }

    public function testRowFilterScopesTheSummary(): void
    {
        $formId = $this->seedReviewForm();
        $jar    = $this->adminJar();
        $link   = $this->createLink($formId, [
            'expose_review_summary' => true,
            'row_filter' => ['match' => 'all', 'groups' => [
                ['match' => 'all', 'conditions' => [['field' => 'enum', 'op' => 'in', 'values' => ['ana']]]],
            ]],
        ], $jar);

        $res = $this->request('GET', "public/share/{$link['token']}/review-summary");
        $this->assertSame(200, $res['status']);
        $enums = $this->enumerators($res['json']['data']);
        $this->assertArrayHasKey('ana', $enums);
        $this->assertArrayNotHasKey('bea', $enums);
        @unlink($jar);
    }
}
