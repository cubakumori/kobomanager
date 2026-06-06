<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tests del parser geográfico (puro, sin BD). */
final class GeoTest extends TestCase
{
    public function testParsePointValid(): void
    {
        $this->assertSame([40.4, -3.7], Geo::parsePoint('40.4 -3.7 600 5'));
        $this->assertSame([40.4, -3.7], Geo::parsePoint('  40.4   -3.7  ')); // espacios extra
    }

    public function testParsePointRejectsInvalid(): void
    {
        $this->assertNull(Geo::parsePoint(null));
        $this->assertNull(Geo::parsePoint(''));
        $this->assertNull(Geo::parsePoint('soloUno'));
        $this->assertNull(Geo::parsePoint('0 0'));        // (0,0) sin señal
        $this->assertNull(Geo::parsePoint('100 200'));    // fuera de rango
        $this->assertNull(Geo::parsePoint('abc def'));
    }

    public function testParsePath(): void
    {
        $pts = Geo::parsePath('40.4 -3.7;41.0 -3.6;0 0');
        $this->assertCount(2, $pts); // el (0,0) se descarta
        $this->assertSame([40.4, -3.7], $pts[0]);
    }

    public function testFeaturesGeopointAndPolygon(): void
    {
        $schema = ['fields' => [
            'ubic' => ['type' => 'geopoint'],
            'zona' => ['type' => 'geoshape'],
        ]];
        $payload = [
            'ubic' => '40.4 -3.7',
            'zona' => '40 -3;41 -3;41 -4;40 -3',
        ];
        $f = Geo::features($payload, $schema, ['ubic' => 'Ubicación']);
        $kinds = array_column($f, 'kind');
        $this->assertContains('point', $kinds);
        $this->assertContains('polygon', $kinds);
        $point = $f[array_search('point', $kinds, true)];
        $this->assertSame('Ubicación', $point['label']);
    }

    public function testFeaturesFallsBackToGeolocation(): void
    {
        $f = Geo::features(['_geolocation' => [12.5, -70.0]], ['fields' => []]);
        $this->assertCount(1, $f);
        $this->assertSame('_geolocation', $f[0]['field']);
        $this->assertSame([[12.5, -70.0]], $f[0]['points']);
    }

    public function testPrimaryPointAndFieldPaths(): void
    {
        $schema = ['fields' => ['ubic' => ['type' => 'geopoint'], 'otro' => ['type' => 'text']]];
        $this->assertSame([40.4, -3.7], Geo::primaryPoint(['ubic' => '40.4 -3.7'], $schema));
        $this->assertSame(['ubic'], Geo::geoFieldPaths($schema));
    }
}
