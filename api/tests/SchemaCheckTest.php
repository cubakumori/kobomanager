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
        return $have;
    }

    public function testNoneMissingWhenAllPresent(): void
    {
        $this->assertSame([], SchemaCheck::missingAgainst($this->fullHave()));
    }

    public function testDetectsAbsentColumn(): void
    {
        $have = $this->fullHave();
        unset($have['share_links.stats_status']); // simula columna ausente
        $missing = SchemaCheck::missingAgainst($have);
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

    public function testCanonicalSchemaSatisfiesAllChecks(): void
    {
        // La BD de test se crea desde db/*.sql: no debe faltar ninguna columna esperada.
        // Si falla, hay una entrada de CHECKS con tabla/columna que no existe en el esquema.
        $this->assertSame([], SchemaCheck::missing());
        $this->assertTrue(SchemaCheck::isUpToDate());
    }
}
