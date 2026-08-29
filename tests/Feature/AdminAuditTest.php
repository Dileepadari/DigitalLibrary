<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class AdminAuditTest extends DatabaseTestCase
{
    /** Does a few things that leave an audit trail, and returns the admin. */
    private function withHistory(): array
    {
        $admin = $this->makeUser('boss', 'admin');
        $target = $this->makeUser('asha');

        $this->signIn($admin['email']);
        $this->post('/admin/users/' . $target['id'] . '/role', ['role' => 'librarian']);
        $this->post('/admin/users/' . $target['id'] . '/status', ['status' => 'muted', 'reason' => 'Noisy']);

        return $admin;
    }

    public function testTheLogShowsWhatHappened(): void
    {
        $this->withHistory();

        $body = $this->get('/admin/audit')->body();

        $this->assertStringContainsString('user.role_changed', $body);
        $this->assertStringContainsString('user.status_changed', $body);
        $this->assertStringContainsString('boss', $body);
    }

    public function testItFiltersByActionAndActor(): void
    {
        $this->withHistory();

        // The rows put the action in a <code>; the filter dropdown lists every
        // action there has ever been, so match the row and not the page.
        $byAction = $this->get('/admin/audit?action=user.role_changed')->body();
        $this->assertStringContainsString('<code>user.role_changed</code>', $byAction);
        $this->assertStringNotContainsString('<code>user.status_changed</code>', $byAction);

        $byOther = $this->get('/admin/audit?actor=nobody')->body();
        $this->assertStringContainsString('Nothing matches those filters', $byOther);
    }

    public function testItExportsAsCsv(): void
    {
        $this->withHistory();

        $response = $this->get('/admin/audit.csv');
        $csv = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('text/csv', $response->headers()['Content-Type']);
        $this->assertStringContainsString('attachment; filename="audit-', $response->headers()['Content-Disposition']);
        $this->assertStringStartsWith('id,when,actor,action,subject_type,subject_id,before,after', $csv);
        $this->assertStringContainsString('user.role_changed', $csv);
        $this->assertStringContainsString('boss', $csv);
    }

    public function testTheExportHonoursTheFilter(): void
    {
        $this->withHistory();

        $csv = $this->get('/admin/audit.csv?action=user.status_changed')->body();

        $this->assertStringContainsString('user.status_changed', $csv);
        $this->assertStringNotContainsString('user.role_changed', $csv);
    }

    public function testALibrarianCannotReadTheLog(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->assertSame(403, $this->get('/admin/audit')->status());
        $this->assertSame(403, $this->get('/admin/audit.csv')->status());
    }

    public function testTheStorageDashboardReportsWhatIsOnDisk(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        $body = $this->text($this->get('/admin/storage')->body());

        $this->assertStringContainsString('Library', $body);
        $this->assertStringContainsString('Quarantine', $body);
        $this->assertStringContainsString('Every file on record is where it should be', $body);
    }

    public function testTheStorageDashboardNoticesAMissingFile(): void
    {
        $target = $this->makeReadableBook();
        $container = $this->kernel()->container();
        $file = $container->get(\App\Repositories\BookFileRepository::class)->findById($target['file_id']);
        $this->assertNotNull($file);

        $container->get(\App\Core\Storage::class)->delete($file->storagePath);

        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        $body = $this->text($this->get('/admin/storage')->body());

        $this->assertStringContainsString('1 file(s) are on record but not on disk', $body);
    }
}
