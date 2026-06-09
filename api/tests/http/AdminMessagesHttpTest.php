<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpTestCase.php';

/**
 * Integración HTTP: bandeja admin de mensajes de contacto (/admin/messages).
 * Lista con filtros + contador de nuevos, cambio de estado (leído/archivado),
 * eliminación definitiva (con auditoría) y bloqueo a no-admins.
 */
final class AdminMessagesHttpTest extends HttpTestCase
{
    private function seedMessage(string $name, string $topic = 'general', string $status = 'new'): int
    {
        DB::run(
            'INSERT INTO contact_messages (name, email, org, topic, message, ip, status)
             VALUES (?, ?, NULL, ?, ?, ?, ?)',
            [$name, strtolower(str_replace(' ', '', $name)) . '@x.example', $topic, "Mensaje de $name", '127.0.0.1', $status]
        );
        return (int) DB::conn()->lastInsertId();
    }

    public function testListWithFiltersAndNewCount(): void
    {
        $this->seedUser('admin', 'adm@test.local', 'Secret123!');
        $jar = $this->login('adm@test.local', 'Secret123!');

        $this->seedMessage('Ana', 'general', 'new');
        $this->seedMessage('Bea', 'hire', 'read');
        $this->seedMessage('Carla', 'hire', 'archived');

        $all = $this->request('GET', 'admin/messages', null, $jar);
        $this->assertSame(200, $all['status']);
        $this->assertSame(3, $all['json']['data']['total']);
        $this->assertSame(1, $all['json']['data']['new_count']);

        $hire = $this->request('GET', 'admin/messages?topic=hire', null, $jar);
        $this->assertSame(2, $hire['json']['data']['total']);

        $archived = $this->request('GET', 'admin/messages?status=archived', null, $jar);
        $this->assertSame(1, $archived['json']['data']['total']);
        $this->assertSame('Carla', $archived['json']['data']['items'][0]['name']);
        // El contador de nuevos es global, no depende del filtro.
        $this->assertSame(1, $archived['json']['data']['new_count']);
    }

    public function testStatusUpdateAndArchiveIsAudited(): void
    {
        $this->seedUser('admin', 'adm@test.local', 'Secret123!');
        $jar = $this->login('adm@test.local', 'Secret123!');
        $id  = $this->seedMessage('Ana');

        // new → read (automático al abrir): no se audita.
        $res = $this->request('PUT', "admin/messages/$id", ['status' => 'read'], $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame('read', DB::run('SELECT status FROM contact_messages WHERE id = ?', [$id])->fetchColumn());
        $this->assertSame(0, (int) DB::run("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'contact_message_%'")->fetchColumn());

        // read → archived: sí se audita.
        $this->request('PUT', "admin/messages/$id", ['status' => 'archived'], $jar);
        $this->assertSame('archived', DB::run('SELECT status FROM contact_messages WHERE id = ?', [$id])->fetchColumn());
        $this->assertSame(1, (int) DB::run("SELECT COUNT(*) FROM audit_log WHERE action = 'contact_message_archive'")->fetchColumn());

        $bad = $this->request('PUT', "admin/messages/$id", ['status' => 'inventado'], $jar);
        $this->assertSame(422, $bad['status']);
    }

    public function testDeleteRemovesRowAndAudits(): void
    {
        $this->seedUser('admin', 'adm@test.local', 'Secret123!');
        $jar = $this->login('adm@test.local', 'Secret123!');
        $id  = $this->seedMessage('Ana');

        $res = $this->request('DELETE', "admin/messages/$id", null, $jar);
        $this->assertSame(200, $res['status']);
        $this->assertSame(0, (int) DB::run('SELECT COUNT(*) FROM contact_messages')->fetchColumn());
        $this->assertSame(1, (int) DB::run("SELECT COUNT(*) FROM audit_log WHERE action = 'contact_message_delete'")->fetchColumn());

        $gone = $this->request('DELETE', "admin/messages/$id", null, $jar);
        $this->assertSame(404, $gone['status']);
    }

    public function testViewerCannotAccess(): void
    {
        $this->seedUser('viewer', 'view@test.local', 'Secret123!');
        $jar = $this->login('view@test.local', 'Secret123!');
        $id  = $this->seedMessage('Ana');

        $this->assertSame(403, $this->request('GET', 'admin/messages', null, $jar)['status']);
        $this->assertSame(403, $this->request('PUT', "admin/messages/$id", ['status' => 'read'], $jar)['status']);
        $this->assertSame(403, $this->request('DELETE', "admin/messages/$id", null, $jar)['status']);
    }
}
