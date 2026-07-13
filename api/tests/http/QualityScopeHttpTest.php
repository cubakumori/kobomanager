<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: override transitorio del alcance del Control de calidad
 * (GET /forms/{id}/quality?scope=all|pending_hold). El parámetro sustituye el
 * ajuste global `qc_scope` SOLO para esa petición (toggle de la vista); un valor
 * no reconocido cae al global. El export acepta el mismo parámetro para que el
 * archivo contenga exactamente lo que se ve en pantalla.
 */
final class QualityScopeHttpTest extends HttpTestCase
{
    /** Envío con encuestador (`enum`) y tiempos ISO sin zona; review_status pendiente. */
    private function timed(int $formId, string $uid, string $enum, string $start, string $end): void
    {
        $this->seedSubmission($formId, $uid, [
            '_id'   => crc32($uid) & 0x7fffffff,
            'enum'  => $enum,
            'start' => "2026-07-01T$start",
            'end'   => "2026-07-01T$end",
        ]);
    }

    /**
     * Forma con umbral de duración mínima y DOS infractoras: una pendiente y otra
     * ya aprobada (la aprobada solo debe reportarse con alcance 'all').
     */
    private function seedScenario(): int
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        DB::run(
            'UPDATE forms SET stats_enumerator_field = ?, qc_min_duration = ? WHERE id = ?',
            ['enum', 4, $formId]
        );
        $this->timed($formId, 'short_pend', 'ana', '09:00:00', '09:02:00'); // 2 min → corta, pendiente
        $this->timed($formId, 'short_appr', 'ana', '11:00:00', '11:02:00'); // 2 min → corta, aprobada
        $this->timed($formId, 'ok1',        'ana', '10:00:00', '10:30:00'); // 30 min → ok
        DB::run(
            "UPDATE submissions_cache SET review_status = 'approved' WHERE submission_uid = 'short_appr'"
        );
        return $formId;
    }

    /** Uids de las infracciones reportadas (todos los equipos/encuestadores). */
    private function flaggedUids(array $data): array
    {
        $uids = [];
        foreach ($data['teams'] ?? [] as $team) {
            foreach ($team['enumerators'] ?? [] as $en) {
                foreach ($en['violations'] ?? [] as $v) {
                    $uids[] = $v['uid'];
                }
            }
        }
        sort($uids);
        return $uids;
    }

    public function testDefaultUsesGlobalScope(): void
    {
        $formId = $this->seedScenario();
        $jar = $this->login('admin@test.local', 'Secret123!');

        // Sin parámetro: el global por defecto es pending_hold → la aprobada no cuenta.
        $res = $this->request('GET', "forms/$formId/quality", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('pending_hold', $res['json']['data']['scope']);
        $this->assertSame(['short_pend'], $this->flaggedUids($res['json']['data']));
        @unlink($jar);
    }

    public function testScopeAllOverridesForTheRequest(): void
    {
        $formId = $this->seedScenario();
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality?scope=all", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('all', $res['json']['data']['scope']);
        $this->assertSame(['short_appr', 'short_pend'], $this->flaggedUids($res['json']['data']));

        // El override NO persiste: la siguiente petición sin parámetro vuelve al global.
        $res = $this->request('GET', "forms/$formId/quality", null, $jar);
        $this->assertSame('pending_hold', $res['json']['data']['scope']);
        @unlink($jar);
    }

    public function testScopePendingHoldOverridesGlobalAll(): void
    {
        $formId = $this->seedScenario();
        Settings::set('qc_scope', 'all');
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality?scope=pending_hold", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('pending_hold', $res['json']['data']['scope']);
        $this->assertSame(['short_pend'], $this->flaggedUids($res['json']['data']));
        @unlink($jar);
    }

    public function testInvalidScopeFallsBackToGlobal(): void
    {
        $formId = $this->seedScenario();
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality?scope=bogus", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('pending_hold', $res['json']['data']['scope']);
        $this->assertSame(['short_pend'], $this->flaggedUids($res['json']['data']));
        @unlink($jar);
    }

    public function testExportRespectsScopeParam(): void
    {
        $formId = $this->seedScenario();
        $jar = $this->login('admin@test.local', 'Secret123!');

        // Con scope=all la infractora aprobada entra en el archivo; sin él, no.
        $res = $this->request('GET', "forms/$formId/quality/export?scope=all", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('short_appr', $res['raw']);
        $this->assertStringContainsString('short_pend', $res['raw']);

        $res = $this->request('GET', "forms/$formId/quality/export", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertStringNotContainsString('short_appr', $res['raw']);
        $this->assertStringContainsString('short_pend', $res['raw']);
        @unlink($jar);
    }
}
