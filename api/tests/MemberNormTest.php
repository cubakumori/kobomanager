<?php

declare(strict_types=1);

require_once __DIR__ . '/DbTestCase.php';

/**
 * Unidad de lib/MemberNorm: plegado de la clave, selección de etiqueta y
 * resolutor con alias (tabla member_aliases).
 */
final class MemberNormTest extends DbTestCase
{
    public function testNormKeyFoldsCaseSpacesAndPunctuation(): void
    {
        // Las seis grafías reales del mismo encuestador comparten clave.
        $same = ['C. M. S.', 'C .M. S.', 'C M. S', 'C m. S.', 'C. M. S', 'c.m.s'];
        $keys = array_unique(array_map(fn($v) => MemberNorm::normKey($v), $same));
        $this->assertSame(['cms'], $keys);

        $this->assertNotSame(MemberNorm::normKey('C C G.'), MemberNorm::normKey('C. M. S.'));
        $this->assertSame('hab1', MemberNorm::normKey('HAB#1'));
        $this->assertSame('hab1', MemberNorm::normKey(' hab 1 '));
        // El baile de letras NO se pliega (para eso está el modo alias).
        $this->assertNotSame(MemberNorm::normKey('JLHV'), MemberNorm::normKey('JLVH'));
        // Los acentos tampoco (documentado): «José» ≠ «Jose».
        $this->assertNotSame(MemberNorm::normKey('José'), MemberNorm::normKey('Jose'));
        // Valor de pura puntuación: cae al crudo recortado (no se fusiona con otros).
        $this->assertSame('...', MemberNorm::normKey('...'));
    }

    public function testPickLabelPrefersMostFrequentSpellingDeterministically(): void
    {
        $this->assertSame('ABC', MemberNorm::pickLabel(['abc' => 2, 'ABC' => 5, 'Abc' => 1]));
        // Empate → la menor alfabéticamente (determinista entre ejecuciones).
        $this->assertSame('ABC', MemberNorm::pickLabel(['abc' => 3, 'ABC' => 3]));
        // El canónico del alias manda sobre la frecuencia.
        $this->assertSame('JLHV', MemberNorm::pickLabel(['jlvh' => 9], 'JLHV'));
    }

    public function testResolverModes(): void
    {
        $formId = $this->makeForm();
        DB::run(
            "INSERT INTO member_aliases (form_id, axis, from_key, to_value) VALUES (?, 'member', 'jlvh', 'JLHV')",
            [$formId]
        );

        $raw = MemberNorm::resolver('raw', $formId);
        $this->assertSame(['key' => 'C. M. S.', 'canon' => null], $raw('member', 'C. M. S.'));

        $norm = MemberNorm::resolver('normalize', $formId);
        $this->assertSame(['key' => 'cms', 'canon' => null], $norm('member', 'C. M. S.'));
        // En 'normalize' los alias NO aplican.
        $this->assertSame(['key' => 'jlvh', 'canon' => null], $norm('member', 'JLVH'));

        $alias = MemberNorm::resolver('alias', $formId);
        // La variante con alias cae en el cubo del canónico, con etiqueta canónica.
        $this->assertSame(['key' => 'jlhv', 'canon' => 'JLHV'], $alias('member', 'JLVH'));
        // Sin alias para esa clave (o para ese eje): normalización a secas.
        $this->assertSame(['key' => 'cms', 'canon' => null], $alias('member', 'C. M. S.'));
        $this->assertSame(['key' => 'jlvh', 'canon' => null], $alias('team', 'JLVH'));

        // Modo inválido → 'normalize' (a prueba de valores corruptos en BD).
        $this->assertSame('normalize', MemberNorm::mode('whatever'));
        $this->assertSame('raw', MemberNorm::mode('raw'));
    }
}
