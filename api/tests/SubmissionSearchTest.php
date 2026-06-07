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
