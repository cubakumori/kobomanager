<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Orden por COLUMNA DE DATOS en la tabla de envíos (`sort=field:<clave>_asc|_desc`):
 * global (SQL), numérico con CAST según el tipo del esquema, vacíos al final, y
 * fallback silencioso al orden por fecha si la clave no existe o está oculta para
 * el usuario (FieldScope) — una vista guardada obsoleta no debe romper la tabla.
 */
final class SubmissionsSortHttpTest extends HttpTestCase
{
    /** Esquema normalizado mínimo: una pregunta numérica y una de texto. */
    private function schemaJson(): string
    {
        return json_encode([
            'languages'        => [null],
            'default_language' => null,
            'fields'           => [
                'edad'   => ['leaf' => 'edad', 'type' => 'integer', 'list' => null, 'multi' => false, 'label' => []],
                'nombre' => ['leaf' => 'nombre', 'type' => 'text', 'list' => null, 'multi' => false, 'label' => []],
            ],
            'choices'     => [],
            'meta'        => [],
            'meta_fields' => [],
        ]);
    }

    /** @return array{formId:int, jar:string} */
    private function setupFormWithRows(): array
    {
        $this->seedUser('admin', 'admin@test.local', 'Secret123!');
        $jar    = $this->login('admin@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId, null, $this->schemaJson());

        // edad como CADENA (así llega de Kobo): «10» < «9» lexicográficamente, el CAST
        // debe imponer el orden numérico. `sub-c` sin nombre (prueba de vacíos al final).
        $this->seedSubmission($formId, 'sub-a', ['_id' => 1, 'edad' => '9', 'nombre' => 'zoe'], '2026-01-03 10:00:00');
        $this->seedSubmission($formId, 'sub-b', ['_id' => 2, 'edad' => '10', 'nombre' => 'ana'], '2026-01-02 10:00:00');
        $this->seedSubmission($formId, 'sub-c', ['_id' => 3, 'edad' => '2'], '2026-01-01 10:00:00');

        return ['formId' => $formId, 'jar' => $jar];
    }

    /** @return string[] uids en el orden devuelto */
    private function uids(array $res): array
    {
        return array_column($res['json']['data']['items'], 'submission_uid');
    }

    public function testNumericColumnSortsByValueNotLexicographically(): void
    {
        ['formId' => $formId, 'jar' => $jar] = $this->setupFormWithRows();

        $res = $this->request('GET', "forms/$formId/submissions?sort=" . urlencode('field:edad_asc'), null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(['sub-c', 'sub-a', 'sub-b'], $this->uids($res), '2 < 9 < 10 (no «10» < «2» < «9»)');

        $res = $this->request('GET', "forms/$formId/submissions?sort=" . urlencode('field:edad_desc'), null, $jar);
        $this->assertSame(['sub-b', 'sub-a', 'sub-c'], $this->uids($res));
    }

    public function testTextColumnSortsWithEmptiesAlwaysLast(): void
    {
        ['formId' => $formId, 'jar' => $jar] = $this->setupFormWithRows();

        $res = $this->request('GET', "forms/$formId/submissions?sort=" . urlencode('field:nombre_asc'), null, $jar);
        $this->assertSame(['sub-b', 'sub-a', 'sub-c'], $this->uids($res), 'ana, zoe y el vacío al final');

        // También en descendente el vacío queda al final (no «primero por NULL»).
        $res = $this->request('GET', "forms/$formId/submissions?sort=" . urlencode('field:nombre_desc'), null, $jar);
        $this->assertSame(['sub-a', 'sub-b', 'sub-c'], $this->uids($res), 'zoe, ana y el vacío al final');
    }

    public function testHiddenFieldSortFallsBackToDateOrder(): void
    {
        ['formId' => $formId] = $this->setupFormWithRows();
        $viewerId = $this->seedUser('viewer', 'viewer@test.local', 'Secret123!');
        $this->grant($viewerId, $formId, true, false, false, null, ['hidden' => ['edad']]);
        $jar = $this->login('viewer@test.local', 'Secret123!');

        // Ordenar por un campo oculto filtraría sus valores: cae al orden por fecha
        // (más recientes primero) sin error, para no romper vistas guardadas.
        $res = $this->request('GET', "forms/$formId/submissions?sort=" . urlencode('field:edad_asc'), null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(['sub-a', 'sub-b', 'sub-c'], $this->uids($res));
    }

    public function testUnknownFieldSortFallsBackToDateOrder(): void
    {
        ['formId' => $formId, 'jar' => $jar] = $this->setupFormWithRows();

        $res = $this->request('GET', "forms/$formId/submissions?sort=" . urlencode('field:no_existe_asc'), null, $jar);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(['sub-a', 'sub-b', 'sub-c'], $this->uids($res));
    }
}
