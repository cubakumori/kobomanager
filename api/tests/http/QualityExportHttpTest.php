<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: export del drill-down de infracciones
 * (GET /forms/{id}/quality/export). Reutiliza lib/Quality como la página, así que
 * exporta exactamente las mismas infracciones (mismo alcance, banderas y estado).
 */
final class QualityExportHttpTest extends HttpTestCase
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

    /** Forma con encuestador por campo `enum` y umbral de duración mínima (minutos). */
    private function makeQcForm(int $accId, int $minDuration): int
    {
        $formId = $this->seedForm($accId);
        DB::run(
            'UPDATE forms SET stats_enumerator_field = ?, qc_min_duration = ? WHERE id = ?',
            ['enum', $minDuration, $formId]
        );
        return $formId;
    }

    public function testCsvExportListsFlaggedRows(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->makeQcForm($accId, 4);
        $this->timed($formId, 'short1', 'ana', '09:00:00', '09:02:00'); // 2 min < 4 → corta
        $this->timed($formId, 'ok1',    'ana', '10:00:00', '10:30:00'); // 30 min → ok
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality/export", null, $jar);
        $this->assertSame(200, $res['status']);
        // Cabeceras (idioma del usuario = es) y la fila marcada; la ok NO aparece.
        $this->assertStringContainsString('Encuestador', $res['raw']);
        $this->assertStringContainsString('short1', $res['raw']);
        $this->assertStringContainsString('ana', $res['raw']);
        $this->assertStringContainsString('Corta', $res['raw']); // etiqueta de bandera es
        $this->assertStringContainsString('120', $res['raw']);    // duración en segundos
        $this->assertStringNotContainsString('ok1', $res['raw']);
        @unlink($jar);
    }

    public function testXlsxExportReturnsZip(): void
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->makeQcForm($accId, 4);
        $this->timed($formId, 'short1', 'ana', '09:00:00', '09:02:00');
        $jar = $this->login('admin@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality/export?format=xlsx", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertStringStartsWith('PK', $res['raw']); // un .xlsx es un ZIP
        @unlink($jar);
    }

    public function testViewerWithoutPermissionForbidden(): void
    {
        $this->seedUser('viewer', 'v@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->makeQcForm($accId, 4);
        // Sin grant: el viewer no tiene can_view sobre el formulario.
        $jar = $this->login('v@test.local', 'Secret123!');

        $res = $this->request('GET', "forms/$formId/quality/export", null, $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }
}
