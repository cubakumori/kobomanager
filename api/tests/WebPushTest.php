<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests de lib/WebPush (Web Push nativo, sin dependencias).
 *
 * El cifrado se blinda con el VECTOR DE PRUEBA OFICIAL del RFC 8291 §5 (claves,
 * salt y salida conocidos): si la implementación se desvía un byte, el test falla.
 * La firma VAPID (ES256) se verifica con openssl contra la clave pública. Nada
 * toca la red ni la BD.
 */
final class WebPushTest extends TestCase
{
    // Vector de prueba del RFC 8291 §5 (base64url, literal del RFC).
    private const UA_PUBLIC   = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const AUTH_SECRET = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const AS_PRIVATE  = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const AS_PUBLIC   = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    private const SALT        = 'DGv6ra1nlYgDCS1FRnbzlw';
    private const PLAINTEXT   = 'When I grow up, I want to be a watermelon';
    private const EXPECTED    = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function testEncryptMatchesRfc8291TestVector(): void
    {
        $body = WebPush::encrypt(
            self::PLAINTEXT,
            WebPush::b64uDecode(self::UA_PUBLIC),
            WebPush::b64uDecode(self::AUTH_SECRET),
            WebPush::b64uDecode(self::AS_PRIVATE),
            WebPush::b64uDecode(self::AS_PUBLIC),
            WebPush::b64uDecode(self::SALT)
        );
        $this->assertSame(self::EXPECTED, WebPush::b64uEncode($body));
    }

    public function testEncryptWithEphemeralKeysProducesValidStructure(): void
    {
        // Sin claves inyectadas (camino de producción): la estructura del cuerpo debe
        // ser salt(16) + rs(4) + idlen(1)=65 + as_public(65, punto sin comprimir) +
        // ciphertext+tag (payload + delimitador + 16 de tag GCM).
        $body = WebPush::encrypt('hola', WebPush::b64uDecode(self::UA_PUBLIC), WebPush::b64uDecode(self::AUTH_SECRET));
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        $this->assertSame(65, ord($body[20]));
        $this->assertSame("\x04", $body[21]);
        $this->assertSame(16 + 4 + 1 + 65 + strlen('hola') + 1 + 16, strlen($body));
    }

    public function testEncryptRejectsMalformedSubscriptionKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WebPush::encrypt('x', 'corto', WebPush::b64uDecode(self::AUTH_SECRET));
    }

    public function testSignJwtVerifiesWithPublicKeyAndCarriesClaims(): void
    {
        $keys = WebPush::generateKeys();
        $jwt  = WebPush::signJwt(
            ['aud' => 'https://fcm.googleapis.com', 'exp' => 1800000000, 'sub' => 'mailto:x@y.z'],
            WebPush::b64uDecode($keys['private']),
            WebPush::b64uDecode($keys['public'])
        );

        [$h, $p, $s] = explode('.', $jwt);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::b64uDecode($h), true));
        $claims = json_decode(WebPush::b64uDecode($p), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame('mailto:x@y.z', $claims['sub']);

        // Firma cruda r‖s → DER, verificada con la clave pública.
        $sig   = WebPush::b64uDecode($s);
        $this->assertSame(64, strlen($sig));
        $toDer = function (string $c): string {
            $c = ltrim($c, "\x00");
            if ($c === '' ) $c = "\x00";
            if (ord($c[0]) > 0x7f) $c = "\x00" . $c;
            return chr(2) . chr(strlen($c)) . $c;
        };
        $der = $toDer(substr($sig, 0, 32)) . $toDer(substr($sig, 32));
        $der = chr(0x30) . chr(strlen($der)) . $der;
        $ok  = openssl_verify(
            "$h.$p",
            $der,
            openssl_pkey_get_public(WebPush::publicPem(WebPush::b64uDecode($keys['public']))),
            OPENSSL_ALGO_SHA256
        );
        $this->assertSame(1, $ok);
    }

    public function testVapidHeaderShapeAndAudience(): void
    {
        // Las claves VAPID de test están definidas en tests/config.http.php.
        $hdr = WebPush::vapidHeader('https://updates.push.services.mozilla.com/wpush/v2/abc', 1800000000);
        $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]+$/', $hdr);
        preg_match('/t=([^,]+),/', $hdr, $m);
        $claims = json_decode(WebPush::b64uDecode(explode('.', $m[1])[1]), true);
        $this->assertSame('https://updates.push.services.mozilla.com', $claims['aud']);
        $this->assertSame('mailto:tests@test.local', $claims['sub']);
        $this->assertSame(1800000000 + 12 * 3600, $claims['exp']);
    }

    public function testGenerateKeysRoundTrip(): void
    {
        $keys = WebPush::generateKeys();
        $pub  = WebPush::b64uDecode($keys['public']);
        $priv = WebPush::b64uDecode($keys['private']);
        $this->assertSame(65, strlen($pub));
        $this->assertSame("\x04", $pub[0]);
        $this->assertSame(32, strlen($priv));
        // El PEM reconstruido desde los bytes crudos debe ser una clave válida.
        $this->assertNotFalse(openssl_pkey_get_private(WebPush::privatePem($priv, $pub)));
        $this->assertNotFalse(openssl_pkey_get_public(WebPush::publicPem($pub)));
    }

    public function testBase64UrlRoundTripAndValidation(): void
    {
        $bin = random_bytes(33);
        $this->assertSame($bin, WebPush::b64uDecode(WebPush::b64uEncode($bin)));
        $this->expectException(InvalidArgumentException::class);
        WebPush::b64uDecode('no válido!!');
    }
}
