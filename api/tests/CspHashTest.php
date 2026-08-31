<?php

use PHPUnit\Framework\TestCase;

/**
 * El hash CSP del <script> inline de tema de index.html debe coincidir en TODOS
 * los sitios que lo fijan (public/.htaccess y los dos bloques de DEPLOY.md §6).
 * Si este test falla es que alguien editó el script de tema sin recalcular el
 * hash: con la CSP desincronizada, el navegador bloquea el script en producción
 * y el modo oscuro deja de inicializarse (regresión real detectada en 1.52.0).
 */
class CspHashTest extends TestCase {

    private function repoRoot(): string {
        return dirname(__DIR__, 2);
    }

    private function themeScriptHash(): string {
        $html = (string) file_get_contents($this->repoRoot() . '/index.html');
        $this->assertMatchesRegularExpression('#<script>.*?</script>#s', $html, 'index.html debe tener el script inline de tema');
        preg_match('#<script>(.*?)</script>#s', $html, $m);
        return "'sha256-" . base64_encode(hash('sha256', $m[1], true)) . "'";
    }

    public function testHtaccessCarriesTheRealHash(): void {
        $hash = $this->themeScriptHash();
        $ht   = (string) file_get_contents($this->repoRoot() . '/public/.htaccess');
        $this->assertStringContainsString($hash, $ht, "public/.htaccess no lleva el hash real del script de tema ($hash)");
        // Un solo hash en la CSP: si aparece otro sha256- distinto, hay uno viejo olvidado.
        preg_match_all("#'sha256-[A-Za-z0-9+/=]+'#", $ht, $all);
        $this->assertSame([$hash], array_values(array_unique($all[0])), 'public/.htaccess lleva algún hash sha256 que no es el del script de tema');
    }

    public function testDeployDocCarriesTheRealHash(): void {
        $hash   = $this->themeScriptHash();
        $deploy = (string) file_get_contents($this->repoRoot() . '/DEPLOY.md');
        preg_match_all("#'sha256-[A-Za-z0-9+/=]+'#", $deploy, $all);
        $this->assertNotEmpty($all[0], 'DEPLOY.md debería documentar la CSP con el hash del script de tema');
        $this->assertSame([$hash], array_values(array_unique($all[0])), 'DEPLOY.md lleva algún hash sha256 que no es el del script de tema');
    }
}
