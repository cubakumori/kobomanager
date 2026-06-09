<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Tests de los valores calculados por envío (puro, sin BD). */
final class DerivedTest extends TestCase
{
    /** Esquema normalizado de ejemplo: 4 preguntas de datos + meta start/end. */
    private function schema(): array
    {
        return [
            'fields' => [
                'q1'       => ['leaf' => 'q1', 'type' => 'text'],
                'q2'       => ['leaf' => 'q2', 'type' => 'integer'],
                'q3'       => ['leaf' => 'q3', 'type' => 'text'],
                'grp/q4'   => ['leaf' => 'q4', 'type' => 'text'],
            ],
            'meta' => ['start' => 'start', 'end' => 'end'],
        ];
    }

    public function testFullCompute(): void
    {
        $payload = [
            'start'             => '2024-03-01T10:00:00',
            'end'               => '2024-03-01T10:30:00',
            '_submission_time'  => '2024-03-01T10:35:00',
            'q1'                => 'hola',
            'q2'                => 7,
            'q3'                => '',          // vacío → no cuenta
            // q4 ausente
            '_attachments'      => [
                ['mimetype' => 'image/jpeg'],
                ['mimetype' => 'image/png'],
                ['mimetype' => 'audio/mp4'],
            ],
            '_geolocation'      => [12.5, -70.0],
            '_validation_status'=> ['uid' => 'validation_status_approved', 'label' => 'Approved'],
            '_submitted_by'     => 'enum1',
            '__version__'       => 'vABC',
            '_tags'             => ['a', 'b'],
            '_notes'            => [['note' => 'x']],
        ];

        $d = Derived::compute($payload, $this->schema());

        $this->assertSame(1800, $d['duration_s']);          // 30 min
        $this->assertSame(300, $d['upload_delay_s']);       // 5 min
        $this->assertSame(4, $d['questions']);
        $this->assertSame(2, $d['answered']);               // q1, q2
        $this->assertSame(0.5, $d['completeness']);
        $this->assertSame(450.0, $d['speed_s_per_q']);      // 1800 / 4
        $this->assertSame(3, $d['attachments_total']);
        $this->assertSame(['image' => 2, 'audio' => 1, 'video' => 0, 'file' => 0], $d['attachments_by_kind']);
        $this->assertTrue($d['has_attachments']);
        $this->assertTrue($d['has_geo']);
        $this->assertSame(10, $d['submitted_hour']);
        $this->assertSame(5, $d['submitted_dow']);          // 2024-03-01 = viernes
        $this->assertSame('enum1', $d['submitted_by']);
        $this->assertSame('vABC', $d['version']);
        $this->assertSame('validation_status_approved', $d['validation_status']);
        $this->assertSame(2, $d['tags_count']);
        $this->assertSame(1, $d['notes_count']);
    }

    public function testSubmittedHourDowConvertToDisplayTimezone(): void
    {
        // _submission_time llega en UTC; con una zona UTC-5 (sin DST) un envío de
        // las 02:30 UTC del viernes cae a las 21:30 del jueves anterior.
        $payload = ['_submission_time' => '2024-03-01T02:30:00'];

        $utc = Derived::compute($payload, $this->schema());
        $this->assertSame(2, $utc['submitted_hour']);          // sin tz ⇒ UTC
        $this->assertSame(5, $utc['submitted_dow']);           // viernes

        $bogota = Derived::compute($payload, $this->schema(), null, 'America/Bogota');
        $this->assertSame(21, $bogota['submitted_hour']);      // 02:30 − 5 h
        $this->assertSame(4, $bogota['submitted_dow']);        // jueves (día anterior)
    }

    public function testTsAnchorsZonelessAsUtcRegardlessOfServerTz(): void
    {
        // Aunque el servidor PHP esté en otra zona, una marca sin offset se ancla
        // en UTC (así viene _submission_time de Kobo).
        $prev = date_default_timezone_get();
        date_default_timezone_set('America/Havana');
        try {
            $d = Derived::compute(['_submission_time' => '2024-03-01T10:35:00'], $this->schema());
            $this->assertSame(10, $d['submitted_hour']);       // 10 UTC, no la hora local del server
        } finally {
            date_default_timezone_set($prev);
        }
    }

    public function testTzMetaDefaultsToUtc(): void
    {
        // Sin APP_TIMEZONE definido (entorno de test) ⇒ UTC.
        $meta = Derived::tzMeta();
        $this->assertSame('UTC', $meta['id']);
        $this->assertSame('UTC', $meta['offset']);
        $this->assertSame(0, $meta['offset_min']);
    }

    public function testMissingStartEndYieldsNull(): void
    {
        $payload = ['q1' => 'a', '_submission_time' => '2024-03-01T10:35:00'];
        $d = Derived::compute($payload, $this->schema());

        $this->assertNull($d['duration_s']);     // sin start/end
        $this->assertNull($d['upload_delay_s']); // sin end
        $this->assertNull($d['speed_s_per_q']);
        $this->assertFalse($d['has_attachments']);
        $this->assertFalse($d['has_geo']);
        $this->assertSame(0, $d['attachments_total']);
        $this->assertSame(10, $d['submitted_hour']);
        $this->assertNull($d['submitted_by']);
        $this->assertNull($d['validation_status']);
    }

    public function testStartEndFallBackToConventionalKeys(): void
    {
        // Esquema sin `meta`: se usan las claves convencionales start/end del payload.
        $schema  = ['fields' => ['q1' => ['type' => 'text']]];
        $payload = ['start' => '2024-03-01T08:00:00', 'end' => '2024-03-01T08:10:00', 'q1' => 'x'];
        $d = Derived::compute($payload, $schema);

        $this->assertSame(600, $d['duration_s']);
        $this->assertSame(1.0, $d['completeness']); // 1 de 1
    }

    public function testCustomMetaFieldNames(): void
    {
        // Formulario que nombró sus campos de tiempo de forma no estándar.
        $schema = ['fields' => [], 'meta' => ['start' => 'inicio', 'end' => 'g/fin']];
        $payload = ['inicio' => '2024-03-01T08:00:00', 'g/fin' => '2024-03-01T09:00:00'];
        $d = Derived::compute($payload, $schema);

        $this->assertSame(3600, $d['duration_s']);
        $this->assertNull($d['completeness']); // esquema sin preguntas
        $this->assertSame(0, $d['questions']);
    }

    public function testNullSchemaIsSafe(): void
    {
        $d = Derived::compute(['_submitted_by' => 'u'], null);
        $this->assertNull($d['completeness']);
        $this->assertSame(0, $d['questions']);
        $this->assertFalse($d['has_geo']);
        $this->assertSame('u', $d['submitted_by']);
    }

    public function testNegativeOrInvalidIntervalsAreNull(): void
    {
        // end anterior a start (reloj raro) → no se reporta duración negativa.
        $schema = ['fields' => [], 'meta' => ['start' => 'start', 'end' => 'end']];
        $payload = ['start' => '2024-03-01T10:00:00', 'end' => '2024-03-01T09:00:00'];
        $this->assertNull(Derived::compute($payload, $schema)['duration_s']);
    }
}
