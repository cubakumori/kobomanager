<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: favoritos de «Mis formularios» (PUT /forms/{id}/favorite +
 * flag `favorite` en GET /forms) y preferencias de UI (PUT /profile/prefs +
 * `ui_prefs` en /auth/me). Cubre también el corte de acceso: no se puede marcar
 * como favorito un formulario que no se puede ver.
 */
final class FavoritesPrefsHttpTest extends HttpTestCase
{
    public function testFavoriteRoundTrip(): void
    {
        $this->seedUser('admin', 'fav@test.local', 'Secret123!');
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $jar = $this->login('fav@test.local', 'Secret123!');

        // De fábrica no es favorito.
        $forms = $this->request('GET', 'forms', null, $jar);
        $this->assertSame(200, $forms['status']);
        $this->assertFalse($forms['json']['data'][0]['favorite']);

        // Marcar (idempotente) → aparece en el listado.
        $this->assertSame(200, $this->request('PUT', "forms/$formId/favorite", ['favorite' => true], $jar)['status']);
        $this->assertSame(200, $this->request('PUT', "forms/$formId/favorite", ['favorite' => true], $jar)['status']);
        $forms = $this->request('GET', 'forms', null, $jar);
        $this->assertTrue($forms['json']['data'][0]['favorite']);

        // Desmarcar → desaparece.
        $this->request('PUT', "forms/$formId/favorite", ['favorite' => false], $jar);
        $forms = $this->request('GET', 'forms', null, $jar);
        $this->assertFalse($forms['json']['data'][0]['favorite']);
        @unlink($jar);
    }

    public function testFavoriteIsPerUser(): void
    {
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $u1 = $this->seedUser('viewer', 'v1@test.local', 'Secret123!');
        $u2 = $this->seedUser('viewer', 'v2@test.local', 'Secret123!');
        $this->grant($u1, $formId);
        $this->grant($u2, $formId);

        $jar1 = $this->login('v1@test.local', 'Secret123!');
        $jar2 = $this->login('v2@test.local', 'Secret123!');

        $this->request('PUT', "forms/$formId/favorite", ['favorite' => true], $jar1);
        $this->assertTrue($this->request('GET', 'forms', null, $jar1)['json']['data'][0]['favorite']);
        $this->assertFalse($this->request('GET', 'forms', null, $jar2)['json']['data'][0]['favorite']);
        @unlink($jar1);
        @unlink($jar2);
    }

    public function testFavoriteRequiresCanView(): void
    {
        $accId  = $this->seedAccount();
        $formId = $this->seedForm($accId);
        $this->seedUser('viewer', 'noview@test.local', 'Secret123!'); // sin grant
        $jar = $this->login('noview@test.local', 'Secret123!');

        $res = $this->request('PUT', "forms/$formId/favorite", ['favorite' => true], $jar);
        $this->assertSame(403, $res['status']);
        @unlink($jar);
    }

    public function testPrefsRoundTripAndValidation(): void
    {
        $this->seedUser('viewer', 'prefs@test.local', 'Secret123!');
        $jar = $this->login('prefs@test.local', 'Secret123!');

        // Sin preferencias: /auth/me las devuelve a null.
        $me = $this->request('GET', 'auth/me', null, $jar);
        $this->assertNull($me['json']['data']['ui_prefs']);

        // Guardar la vista de «Mis formularios» → persiste y viaja en /auth/me.
        $res = $this->request('PUT', 'profile/prefs', [
            'forms_view' => ['account' => 3, 'type' => 'deployed', 'favorites' => true],
        ], $jar);
        $this->assertSame(200, $res['status']);
        $me = $this->request('GET', 'auth/me', null, $jar);
        $this->assertSame(
            ['account' => 3, 'type' => 'deployed', 'favorites' => true],
            $me['json']['data']['ui_prefs']['forms_view']
        );

        // Tipo fuera de la lista blanca → 400/422 de validación.
        $bad = $this->request('PUT', 'profile/prefs', ['forms_view' => ['type' => 'hacked']], $jar);
        $this->assertGreaterThanOrEqual(400, $bad['status']);
        $this->assertLessThan(500, $bad['status']);

        // Borrar la clave con null → ui_prefs vuelve a null.
        $this->request('PUT', 'profile/prefs', ['forms_view' => null], $jar);
        $me = $this->request('GET', 'auth/me', null, $jar);
        $this->assertNull($me['json']['data']['ui_prefs']);
        @unlink($jar);
    }
}
