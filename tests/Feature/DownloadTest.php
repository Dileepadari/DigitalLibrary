<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Storage;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Repositories\UserRepository;
use App\Services\ModerationService;
use App\Services\UploadPipeline;
use Tests\DatabaseTestCase;

final class DownloadTest extends DatabaseTestCase
{
    private string $contents = '';

    /**
     * A published file on a published book, uploaded by a librarian so it skips
     * the queue.
     *
     * @return array{file_id: int, slug: string, uploader: int}
     */
    private function publishedFile(): array
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $container = $this->kernel()->container();

        $bookId = $this->makeBook('A Downloadable Book', ['added_by' => $librarian['id']]);
        $book = $container->get(BookRepository::class)->findById($bookId);
        $user = $container->get(UserRepository::class)->findById($librarian['id']);

        $this->assertNotNull($book);
        $this->assertNotNull($user);

        $this->contents = $this->pdfBytes('downloadable');
        $result = $container->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->contents),
            $book,
            $user
        );

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertNotNull($result->file);

        $container->get(ModerationService::class)->submitUpload($book, $result->file, $user, true);

        return ['file_id' => $result->file->id, 'slug' => $book->slug, 'uploader' => $librarian['id']];
    }

    public function testAGuestIsSentToSignIn(): void
    {
        $file = $this->publishedFile();

        $_SESSION = [];
        $this->assertRedirectedTo('/login', $this->get('/files/' . $file['file_id']));
    }

    public function testAMemberDownloadsTheExactBytes(): void
    {
        $file = $this->publishedFile();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $response = $this->get('/files/' . $file['file_id']);

        $this->assertSame(200, $response->status());
        $this->assertSame($this->contents, $this->bodyOf($response));
        $this->assertSame('application/pdf', $response->headers()['Content-Type']);
        $this->assertSame((string) strlen($this->contents), $response->headers()['Content-Length']);
        $this->assertStringContainsString('attachment;', $response->headers()['Content-Disposition']);
        $this->assertSame('bytes', $response->headers()['Accept-Ranges']);
    }

    public function testTheInlineFlagOpensRatherThanDownloads(): void
    {
        $file = $this->publishedFile();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $response = $this->get('/files/' . $file['file_id'] . '?inline');

        $this->assertStringContainsString('inline;', $response->headers()['Content-Disposition']);
    }

    public function testARangeRequestReturnsPartialContent(): void
    {
        $file = $this->publishedFile();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $response = $this->kernel()->handle(
            \App\Core\Request::create('GET', '/files/' . $file['file_id'])->withHeaders(['range' => 'bytes=0-9'])
        );

        $this->assertSame(206, $response->status());
        $this->assertSame('bytes 0-9/' . strlen($this->contents), $response->headers()['Content-Range']);
        $this->assertSame('10', $response->headers()['Content-Length']);
        $this->assertSame(substr($this->contents, 0, 10), $this->bodyOf($response));
    }

    public function testARangeBeyondTheEndIsRefused(): void
    {
        $file = $this->publishedFile();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $response = $this->kernel()->handle(
            \App\Core\Request::create('GET', '/files/' . $file['file_id'])
                ->withHeaders(['range' => 'bytes=999999-1000000'])
        );

        $this->assertSame(416, $response->status());
    }

    public function testDownloadsAreCounted(): void
    {
        $file = $this->publishedFile();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $this->bodyOf($this->get('/files/' . $file['file_id']));
        $this->bodyOf($this->get('/files/' . $file['file_id']));

        $this->assertSame(
            2,
            (int) $this->db->scalar('SELECT download_count FROM book_files WHERE id = ?', [$file['file_id']])
        );
        $this->assertSame(
            2,
            (int) $this->db->scalar('SELECT download_count FROM books WHERE slug = ?', [$file['slug']])
        );
    }

    public function testAQuarantinedFileIsInvisibleToOtherMembers(): void
    {
        $uploader = $this->makeUser('asha');
        $container = $this->kernel()->container();
        $bookId = $this->makeBook('Waiting Book', ['status' => 'pending', 'added_by' => $uploader['id']]);
        $book = $container->get(BookRepository::class)->findById($bookId);
        $user = $container->get(UserRepository::class)->findById($uploader['id']);

        $this->assertNotNull($book);
        $this->assertNotNull($user);

        $result = $container->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('quarantined')),
            $book,
            $user
        );
        $this->assertNotNull($result->file);
        $fileId = $result->file->id;

        // The uploader may look at their own.
        $this->signIn($uploader['email']);
        $this->assertSame(200, $this->get('/files/' . $fileId)->status());

        // Another member may not know it exists.
        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);
        $this->assertSame(404, $this->get('/files/' . $fileId)->status());

        // A reviewer has to be able to open it.
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->assertSame(200, $this->get('/files/' . $fileId)->status());

        // And a quarantined read is not a download.
        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT download_count FROM book_files WHERE id = ?', [$fileId])
        );
    }

    public function testAMissingFileOnDiskIs404RatherThanAnError(): void
    {
        $file = $this->publishedFile();
        $container = $this->kernel()->container();
        $stored = $container->get(BookFileRepository::class)->findById($file['file_id']);
        $this->assertNotNull($stored);

        $container->get(Storage::class)->delete($stored->storagePath);

        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $this->assertSame(404, $this->get('/files/' . $file['file_id'])->status());
    }

    public function testTheQuarantineSweepDeletesRejectedFilesPastTheWindow(): void
    {
        $uploader = $this->makeUser('asha');
        $container = $this->kernel()->container();
        $bookId = $this->makeBook('Rejected Book', ['status' => 'pending', 'added_by' => $uploader['id']]);
        $book = $container->get(BookRepository::class)->findById($bookId);
        $user = $container->get(UserRepository::class)->findById($uploader['id']);

        $this->assertNotNull($book);
        $this->assertNotNull($user);

        $result = $container->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('doomed')),
            $book,
            $user
        );
        $this->assertNotNull($result->file);

        $files = $container->get(BookFileRepository::class);
        $storage = $container->get(Storage::class);
        $files->setStatus($result->file->id, 'rejected');

        // Still inside the grace window: nothing to sweep.
        $this->assertSame([], $files->rejectedBefore(gmdate('Y-m-d H:i:s', time() - 8 * 86400)));

        $this->db->execute(
            'UPDATE book_files SET rejected_at = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s', time() - 9 * 86400), $result->file->id]
        );

        $due = $files->rejectedBefore(gmdate('Y-m-d H:i:s', time() - 8 * 86400));
        $this->assertCount(1, $due);

        foreach ($due as $row) {
            $storage->delete($row['storage_path']);
            $files->delete($row['id']);
        }

        $this->assertFalse($storage->exists($result->file->storagePath));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM book_files'));
    }
}
