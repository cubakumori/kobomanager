<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests del helper de búsqueda (lib/SubmissionSearch): proyección a texto
 * buscable (textFor) y construcción del fragmento WHERE (clause). Pura → sin BD.
 */
final class SubmissionSearchTest extends TestCase
{
    public function testTextForSkipsMetadataKeys(): void
    {
        $payload = [
            'nombre'       => 'María López',
            '_id'          => 12345,
            '_uuid'        => 'abc-uuid',
            '_attachments' => [['download_url' => 'https://x/att/audio.aac', 'question_xpath' => 'G/audio1']],
            'edad'         => 33,
        ];
        $txt = SubmissionSearch::textFor($payload);
        $this->assertStringContainsString('María López', $txt);
        $this->assertStringContainsString('33', $txt);
        // Ni metadatos ni rutas de campo de los adjuntos.
        $this->assertStringNotContainsString('audio', $txt);
        $this->assertStringNotContainsString('abc-uuid', $txt);
        $this->assertStringNotContainsString('12345', $txt);
    }

    public function testTextForFlattensNestedGroups(): void
    {
        $payload = ['G01' => ['p1' => 'Habana', 'p2' => 'Cuba'], 'top' => 'Z'];
        $txt = SubmissionSearch::textFor($payload);
        foreach (['Habana', 'Cuba', 'Z'] as $needle) {
            $this->assertStringContainsString($needle, $txt);
        }
    }

    public function testTextForIgnoresBoolAndNull(): void
    {
        $txt = SubmissionSearch::textFor(['a' => true, 'b' => null, 'c' => 'real']);
        $this->assertSame('real', $txt);
    }

    public function testTextForAppendsOptionLabelsKeepingCodes(): void
    {
        // Mapa ruta => código => "etiquetas" (lo que produce FormSchema::searchOptionLabels).
        $optionLabels = [
            'G01/P1_3' => ['1' => 'Masculino', '2' => 'Femenino'],
            'categs'   => ['1' => 'Familiar', '7' => 'Madre soltera'],
        ];
        $payload = ['G01/P1_3' => '2', 'categs' => '1 7', 'nombre' => 'Ana'];
        $txt = SubmissionSearch::textFor($payload, $optionLabels);

        // El valor crudo (código) sigue presente y se añade la etiqueta legible.
        $this->assertStringContainsString('Ana', $txt);
        $this->assertStringContainsString('2', $txt);          // código select_one
        $this->assertStringContainsString('Femenino', $txt);   // su etiqueta
        // select_multiple: cada código elegido aporta su etiqueta.
        $this->assertStringContainsString('Familiar', $txt);
        $this->assertStringContainsString('Madre soltera', $txt);
        // Una opción NO elegida (código 1 de P1_3 = Masculino) no se indexa.
        $this->assertStringNotContainsString('Masculino', $txt);
    }

    public function testTextForWithoutLabelsUnchanged(): void
    {
        // Sin mapa de etiquetas, el comportamiento es el de siempre (solo valores crudos).
        $this->assertSame('Ana', SubmissionSearch::textFor(['nombre' => 'Ana']));
    }

    public function testSearchOptionLabelsMergesLanguages(): void
    {
        // Esquema con un select_one bilingüe → la etiqueta indexada une ambos idiomas.
        $schema = [
            'fields'  => ['sex' => ['leaf' => 'sex', 'list' => 'L1', 'multi' => false, 'label' => []]],
            'choices' => ['L1' => ['2' => ['es' => 'Femenino', 'en' => 'Female']]],
        ];
        $map = FormSchema::searchOptionLabels($schema);
        $this->assertArrayHasKey('sex', $map);
        $this->assertStringContainsString('Femenino', $map['sex']['2']);
        $this->assertStringContainsString('Female', $map['sex']['2']);
    }

    public function testClauseBuildsBooleanPrefixMatch(): void
    {
        [$sql, $params] = SubmissionSearch::clause('sc', 'Maria');
        $this->assertSame('MATCH(sc.search_text) AGAINST (? IN BOOLEAN MODE)', $sql);
        $this->assertSame(['+Maria*'], $params);
    }

    public function testClauseMultiWordAndsTokens(): void
    {
        [$sql, $params] = SubmissionSearch::clause('sc', 'maria ramona');
        $this->assertStringStartsWith('MATCH(', $sql);
        $this->assertSame(['+maria* +ramona*'], $params);
    }

    public function testClauseStripsBooleanOperators(): void
    {
        // Los operadores del propio término se eliminan; "ma+ria" → "maria".
        [, $params] = SubmissionSearch::clause('sc', 'ma+ria');
        $this->assertSame(['+maria*'], $params);
    }

    public function testClauseFallsBackToLikeForShortTerms(): void
    {
        [$sql, $params] = SubmissionSearch::clause('sc', 'ab');
        $this->assertSame('sc.search_text LIKE ?', $sql);
        $this->assertSame(['%ab%'], $params);
    }
}
