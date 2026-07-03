<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SqlScript::split — el splitter compartido por el instalador y la semilla de
 * la demo. Debe respetar `;` dentro de cadenas, identificadores y comentarios.
 */
final class SqlScriptTest extends TestCase
{
    public function testSplitsSimpleStatements(): void
    {
        $stmts = SqlScript::split("SELECT 1;\nSELECT 2;\n");
        $this->assertSame(['SELECT 1', 'SELECT 2'], $stmts);
    }

    public function testSemicolonInsideStringIsNotASeparator(): void
    {
        $stmts = SqlScript::split("INSERT INTO t (a) VALUES ('uno; dos');INSERT INTO t (a) VALUES (\"tres; cuatro\");");
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('uno; dos', $stmts[0]);
        $this->assertStringContainsString('tres; cuatro', $stmts[1]);
    }

    public function testEscapedQuoteInsideStringDoesNotCloseIt(): void
    {
        $stmts = SqlScript::split("INSERT INTO t (a) VALUES ('l\\'un; l\\'autre');SELECT 1;");
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString("l\\'un; l\\'autre", $stmts[0]);
    }

    public function testSemicolonInsideCommentsIsNotASeparator(): void
    {
        $sql = "-- comentario; con punto y coma\nSELECT 1;\n/* bloque; con punto\ny coma */ SELECT 2;";
        $stmts = SqlScript::split($sql);
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('SELECT 1', $stmts[0]);
        $this->assertStringContainsString('SELECT 2', $stmts[1]);
    }

    public function testCommentOnlyLeftoversAreDiscarded(): void
    {
        $stmts = SqlScript::split("SELECT 1;\n-- solo un comentario final\n");
        $this->assertSame(1, count($stmts));
    }

    public function testStatementWithoutTrailingSemicolonIsKept(): void
    {
        $stmts = SqlScript::split('SELECT 1; SELECT 2');
        $this->assertSame(['SELECT 1', 'SELECT 2'], $stmts);
    }
}
