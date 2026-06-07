<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests del helper de adjuntos (lib/Attachments): clasificación por tipo y
 * normalización del array desde `_attachments`. Clase pura → TestCase sin BD.
 */
final class AttachmentsTest extends TestCase
{
    public function testKindByMimePrefix(): void
    {
        $this->assertSame('image', Attachments::kind('image/jpeg'));
        $this->assertSame('audio', Attachments::kind('audio/aac'));
        $this->assertSame('video', Attachments::kind('video/mp4'));
    }

    public function testKindDocuments(): void
    {
        $this->assertSame('document', Attachments::kind('application/pdf'));
        $this->assertSame('document', Attachments::kind('text/csv'));
        $this->assertSame('document', Attachments::kind('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertSame('document', Attachments::kind('application/vnd.oasis.opendocument.spreadsheet'));
    }

    public function testKindFallbackToFile(): void
    {
        $this->assertSame('file', Attachments::kind('application/octet-stream'));
        $this->assertSame('file', Attachments::kind('application/zip'));
        $this->assertSame('file', Attachments::kind(''));
    }

    public function testForPayloadNormalizesAndSkipsWithoutUid(): void
    {
        $payload = [
            '_attachments' => [
                ['uid' => 'att1', 'mimetype' => 'image/png', 'media_file_basename' => 'foto.png', 'question_xpath' => 'g/p'],
                ['mimetype' => 'audio/aac'], // sin uid → se ignora
                ['uid' => 'att2', 'mimetype' => 'audio/aac', 'filename' => 'path/clip.aac'],
            ],
        ];
        $out = Attachments::forPayload($payload);
        $this->assertCount(2, $out);
        $this->assertSame('att1', $out[0]['uid']);
        $this->assertSame('foto.png', $out[0]['name']);
        $this->assertSame('image', $out[0]['kind']);
        $this->assertSame('g/p', $out[0]['field']);
        // Sin media_file_basename → basename del filename.
        $this->assertSame('clip.aac', $out[1]['name']);
        $this->assertSame('audio', $out[1]['kind']);
    }

    public function testForPayloadEmptyWhenNoAttachments(): void
    {
        $this->assertSame([], Attachments::forPayload([]));
        $this->assertSame([], Attachments::forPayload(['_attachments' => []]));
    }
}
