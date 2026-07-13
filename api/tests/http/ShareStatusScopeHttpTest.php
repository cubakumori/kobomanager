<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP de la VISIBILIDAD del alcance por estado en enlaces públicos:
 * los metadatos exponen `status_scope` (para que la vista anuncie «solo aprobados»)
 * y, en las estadísticas públicas, la tarjeta «Total» se acota al estado del enlace
 * (a diferencia de la vista interna, aquí no hay selector de estado y un enlace
 * «solo aprobados» no debe revelar cuántos envíos sin aprobar existen).
 */
final class ShareStatusScopeHttpTest extends HttpTestCase
{
    /** Forma con 3 envíos: 2 aprobados y 1 pendiente. */
    private function seedFormWithReviews(): int
    {
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedSubmission($formId, 'ap1', ['_id' => 1, 'q' => 'a']);
        $this->seedSubmission($formId, 'ap2', ['_id' => 2, 'q' => 'b']);
        $this->seedSubmission($formId, 'pe1', ['_id' => 3, 'q' => 'c']);
        DB::run("UPDATE submissions_cache SET review_status = 'approved' WHERE submission_uid IN ('ap1','ap2')");
        return $formId;
    }

    private function createLink(int $formId, array $extra): array
    {
        $this->seedUser('admin', 'admin@test.local', 'secret123');
        $jar = $this->login('admin@test.local', 'secret123');
        $res = $this->request('POST', 'admin/shares', array_merge([
            'form_id'      => $formId,
            'expose_list'  => true,
            'expose_stats' => true,
        ], $extra), $jar);
        $this->assertSame(201, $res['status'], 'creación de enlace falló: ' . $res['raw']);
        @unlink($jar);
        return $res['json']['data'];
    }

    public function testMetaExposesStatusScope(): void
    {
        $formId = $this->seedFormWithReviews();
        $link   = $this->createLink($formId, ['stats_status' => 'approved']);

        $meta = $this->request('GET', "public/share/{$link['token']}");
        $this->assertSame(200, $meta['status']);
        $this->assertSame('approved', $meta['json']['data']['status_scope']);
    }

    public function testMetaStatusScopeNullWithoutRestriction(): void
    {
        $formId = $this->seedFormWithReviews();
        $link   = $this->createLink($formId, []);

        $meta = $this->request('GET', "public/share/{$link['token']}");
        $this->assertNull($meta['json']['data']['status_scope']);
    }

    public function testStatsTotalRespectsStatusScope(): void
    {
        $formId = $this->seedFormWithReviews();
        $link   = $this->createLink($formId, ['stats_status' => 'approved']);

        $res = $this->request('GET', "public/share/{$link['token']}/stats");
        $this->assertSame(200, $res['status']);
        $data = $res['json']['data'];
        $this->assertSame(2, $data['total']); // solo los aprobados
        $this->assertSame(2, array_sum(array_column($data['by_day'], 'count')));
    }

    public function testStatsTotalUnchangedWithoutStatusScope(): void
    {
        $formId = $this->seedFormWithReviews();
        $link   = $this->createLink($formId, []);

        $res = $this->request('GET', "public/share/{$link['token']}/stats");
        $this->assertSame(200, $res['status']);
        $this->assertSame(3, $res['json']['data']['total']);
    }
}
