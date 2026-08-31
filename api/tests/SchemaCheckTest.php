<?php

declare(strict_types=1);

/**
 * Tests de lib/SchemaCheck (detección de desfase esquema↔código).
 *
 * La lógica pura (`missingAgainst`) se prueba con mapas sintéticos para no tocar el
 * esquema (un ALTER haría COMMIT implícito y rompería el aislamiento por transacción
 * de DbTestCase). El test de integración confirma que el esquema canónico —del que se
 * construye la BD de test— satisface TODOS los CHECKS (cazaría una entrada con un
 * nombre de tabla/columna equivocado).
 */
final class SchemaCheckTest extends DbTestCase
{
    /** Construye un mapa "tabla.columna" => IS_NULLABLE con TODAS las columnas esperadas presentes. */
    private function fullHave(): array
    {
        $have = [];
        foreach (SchemaCheck::CHECKS as $c) {
            // 'YES' satisface tanto las que exigen NULL como las demás.
            $have[$c['table'] . '.' . $c['column']] = 'YES';
        }
        foreach (SchemaCheck::TABLE_CHECKS as $c) {
            // Basta una columna cualquiera para que la tabla cuente como presente.
            $have[$c['table'] . '.id'] = 'NO';
        }
        return $have;
    }

    /** Mapa "tabla.índice" => true con TODOS los índices esperados presentes. */
    private function fullHaveIndexes(): array
    {
        $idx = [];
        foreach (SchemaCheck::INDEX_CHECKS as $c) {
            $idx[$c['table'] . '.' . $c['index']] = true;
        }
        return $idx;
    }

    public function testDetectsAbsentTable(): void
    {
        $have = $this->fullHave();
        unset($have['contact_messages.id']); // sin columnas = tabla inexistente
        $missing = SchemaCheck::missingAgainst($have, $this->fullHaveIndexes());
        $this->assertCount(1, $missing);
        $this->assertSame('contact_messages', $missing[0]['table']);
        $this->assertNull($missing[0]['column']);
        $this->assertStringContainsString('CREATE TABLE', $missing[0]['fix']);
    }

    public function testNoneMissingWhenAllPresent(): void
    {
        $this->assertSame([], SchemaCheck::missingAgainst($this->fullHave(), $this->fullHaveIndexes()));
    }

    public function testDetectsAbsentIndex(): void
    {
        // Índices presentes vacío = BD anterior a 1.52.0 (uq_form_uid ausente).
        $missing = SchemaCheck::missingAgainst($this->fullHave());
        $this->assertCount(1, $missing);
        $this->assertSame('uq_form_uid', $missing[0]['index']);
        $this->assertStringContainsString('UNIQUE KEY uq_form_uid', $missing[0]['fix']);
    }

    public function testDetectsAbsentColumn(): void
    {
        $have = $this->fullHave();
        unset($have['share_links.stats_status']); // simula columna ausente
        $missing = SchemaCheck::missingAgainst($have, $this->fullHaveIndexes());
        $cols = array_map(fn($m) => $m['table'] . '.' . $m['column'], $missing);
        $this->assertContains('share_links.stats_status', $cols);
        $this->assertCount(1, $missing);
    }

    public function testDetectsNotNullableWhenNullRequired(): void
    {
        // submission_reviews.user_id existe pero NO admite NULL → debe detectarse.
        $have = $this->fullHave();
        $have['submission_reviews.user_id'] = 'NO';
        $cols = array_map(fn($m) => $m['table'] . '.' . $m['column'], SchemaCheck::missingAgainst($have));
        $this->assertContains('submission_reviews.user_id', $cols);
    }

    public function testTableFixesMirrorCanonicalColumns(): void
    {
        // El `fix` de una tabla es una COPIA del CREATE canónico de db/001_schema.sql;
        // si el canónico gana/pierde columnas, esta copia debe seguirle. Se comprueba
        // contra la BD de test (construida desde db/*.sql): cada columna real debe
        // aparecer como definición de columna en el fix.
        foreach (SchemaCheck::TABLE_CHECKS as $chk) {
            $cols = array_column(DB::run(
                'SELECT COLUMN_NAME c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$chk['table']]
            )->fetchAll(), 'c');
            $this->assertNotSame([], $cols, "la tabla {$chk['table']} no existe en la BD de test");
            foreach ($cols as $col) {
                $this->assertMatchesRegularExpression(
                    '/^\s*' . preg_quote($col, '/') . '\s/mi',
                    $chk['fix'],
                    "columna {$chk['table']}.$col ausente en el fix de TABLE_CHECKS"
                );
            }
        }
    }

    public function testCanonicalSchemaSatisfiesAllChecks(): void
    {
        // La BD de test se crea desde db/*.sql: no debe faltar ninguna columna esperada.
        // Si falla, hay una entrada de CHECKS con tabla/columna que no existe en el esquema.
        $this->assertSame([], SchemaCheck::missing());
        $this->assertTrue(SchemaCheck::isUpToDate());
    }
}
