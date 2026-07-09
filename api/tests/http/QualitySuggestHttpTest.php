<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: sugerencia de umbrales QC (GET /forms/{id}/quality/suggest)
 * a partir de los percentiles de duration_s. Requiere permiso de ajustes.
 */
final class QualitySuggestHttpTest extends HttpTestCase
{
    /** Envío con duración exacta en segundos (start/end por convención). */
    private function timedSubmission(int $formId, int $i, int $seconds): void
    {
        $start = new DateTime('2026-01-01T10:00:00', new DateTimeZone('UTC'));
        $end   = (clone $start)->modify("+$seconds seconds");
        $this->seedSubmission($formId, "d$i", [
            '_id'   => $i,
            'start' => $start->format('Y-m-d\TH:i:s'),
            'end'   => $end->format('Y-m-d\TH:i:s'),
        ]);
    }

    public function testSuggestsThresholdsFromPercentiles(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        // 20 duraciones: 1×60s, 18×600s, 1×3600s → p5=60s, p95=600s (índice 18 de 0..19).
        $this->timedSubmission($formId, 1, 60);
        for ($i = 2; $i <= 19; $i++) {
            $this->timedSubmission($formId, $i, 600);
        }
        $this->timedSubmission($formId, 20, 3600);
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality/suggest", null, $jar);
        $this->assertSame(200, $res['status']);
        $d = $res['json']['data'];
        $this->assertSame(20, $d['count']);
        $this->assertSame(60, $d['p5_s']);
        $this->assertSame(600, $d['p95_s']);
        $this->assertSame(1, $d['suggested']['min_duration']);  // floor(60/60), mínimo 1
        $this->assertSame(10, $d['suggested']['max_duration']); // ceil(600/60)
        @unlink($jar);
    }

    public function testNoSuggestionWithTinySample(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->timedSubmission($formId, 1, 300);
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality/suggest", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['json']['data']['count']);
        $this->assertNull($res['json']['data']['suggested']);
        @unlink($jar);
    }

    public function testViewerWithoutSettingsPermissionForbidden(): void
    {
        $uid = $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->grant($uid, $formId, view: true); // sin can_settings
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality/suggest", null, $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }

    public function testEnumeratorMedianReported(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        DB::run('UPDATE forms SET stats_enumerator_field = ? WHERE id = ?', ['enum', $formId]);
        // ana×3, luis×2, marta×1 → conteos [3,2,1] → mediana 2, 3 encuestadores.
        $i = 0;
        foreach (['ana' => 3, 'luis' => 2, 'marta' => 1] as $enum => $n) {
            for ($k = 0; $k < $n; $k++) {
                $this->seedSubmission($formId, "s$i", ['_id' => $i, 'enum' => $enum]);
                $i++;
            }
        }
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality/suggest", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['json']['data']['enumerator_median']);
        $this->assertSame(3, $res['json']['data']['enumerators']);
        @unlink($jar);
    }
}
