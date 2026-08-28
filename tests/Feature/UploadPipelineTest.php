<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Storage;
use App\Repositories\BookRepository;
use App\Repositories\UserRepository;
use App\Services\UploadPipeline;
use Tests\DatabaseTestCase;

final class UploadPipelineTest extends DatabaseTestCase
{
    private function pipeline(): UploadPipeline
    {
        return $this->kernel()->container()->get(UploadPipeline::class);
    }

    /** @return array{0: \App\Models\Book, 1: \App\Models\User} */
    private function subject(string $username = 'asha'): array
    {
        $account = $this->makeUser($username);
        $bookId = $this->makeBook('A Book To Attach To', ['status' => 'pending', 'added_by' => $account['id']]);

        $container = $this->kernel()->container();
        $book = $container->get(BookRepository::class)->findById($bookId);
        $user = $container->get(UserRepository::class)->findById($account['id']);

        $this->assertNotNull($book);
        $this->assertNotNull($user);

        return [$book, $user];
    }

    public function testAValidPdfIsStoredInQuarantine(): void
    {
        [$book, $user] = $this->subject();

        $result = $this->pipeline()->receive($this->makeUpload('book.pdf', $this->pdfBytes()), $book, $user);

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertNotNull($result->file);
        $this->assertSame('quarantined', $result->file->status);
        $this->assertStringStartsWith('quarantine/', $result->file->storagePath);

        $storage = $this->kernel()->container()->get(Storage::class);
        $this->assertTrue($storage->exists($result->file->storagePath));
    }

    public function testTheStoredPathIsShardedByHash(): void
    {
        [$book, $user] = $this->subject();

        $result = $this->pipeline()->receive($this->makeUpload('book.pdf', $this->pdfBytes()), $book, $user);
        $file = $result->file;

        $this->assertNotNull($file);
        $this->assertSame(
            'quarantine/' . substr($file->sha256, 0, 2) . '/' . substr($file->sha256, 2, 2)
                . '/' . $file->sha256 . '.pdf',
            $file->storagePath
        );
    }

    public function testNothingIsPublishedByThePipelineItself(): void
    {
        [$book, $user] = $this->subject();

        $this->pipeline()->receive($this->makeUpload('book.pdf', $this->pdfBytes()), $book, $user);

        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM book_files WHERE status = 'published'"));
    }

    public function testAnExecutableExtensionIsRefused(): void
    {
        [$book, $user] = $this->subject();

        $result = $this->pipeline()->receive($this->makeUpload('shell.php', '<?php echo 1;'), $book, $user);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('never accepted', (string) $result->error);
    }

    public function testAnUnsupportedExtensionIsRefused(): void
    {
        [$book, $user] = $this->subject();

        $result = $this->pipeline()->receive($this->makeUpload('notes.docx', 'anything'), $book, $user);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Accepted formats', (string) $result->error);
    }

    public function testContentsThatDoNotMatchTheExtensionAreRefused(): void
    {
        [$book, $user] = $this->subject();

        // A PHP script wearing a .pdf name.
        $result = $this->pipeline()->receive(
            $this->makeUpload('sneaky.pdf', "<?php system(\$_GET['c']); ?>\n"),
            $book,
            $user
        );

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('contents are', (string) $result->error);
    }

    public function testAPdfWithAJavaScriptActionIsRefused(): void
    {
        [$book, $user] = $this->subject();

        $pdf = str_replace('% Hello', '/OpenAction<</S/JavaScript/JS(app.alert(1))>>', $this->pdfBytes());
        $result = $this->pipeline()->receive($this->makeUpload('active.pdf', $pdf), $book, $user);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('script', (string) $result->error);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM book_files'));
    }

    public function testAFileOverTheLimitIsRefused(): void
    {
        [$book, $user] = $this->subject();

        $upload = $this->makeUpload('big.pdf', $this->pdfBytes());
        $upload['size'] = 300 * 1024 * 1024;

        $result = $this->pipeline()->receive($upload, $book, $user);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('limit is', (string) $result->error);
    }

    public function testAFileOverTheUploadersQuotaIsRefused(): void
    {
        [$book, $user] = $this->subject();
        $this->db->execute('UPDATE users SET storage_quota = 10 WHERE id = ?', [$user->id]);

        $container = $this->kernel()->container();
        $reloaded = $container->get(UserRepository::class)->findById($user->id);
        $this->assertNotNull($reloaded);

        $result = $this->pipeline()->receive($this->makeUpload('book.pdf', $this->pdfBytes()), $book, $reloaded);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('quota', (string) $result->error);
    }

    public function testAnInterruptedUploadIsReported(): void
    {
        [$book, $user] = $this->subject();

        $result = $this->pipeline()->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes(), UPLOAD_ERR_PARTIAL),
            $book,
            $user
        );

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('interrupted', (string) $result->error);
    }

    public function testTheSameBytesTwiceAreRefusedAsADuplicate(): void
    {
        [$book, $user] = $this->subject();

        $this->pipeline()->receive($this->makeUpload('book.pdf', $this->pdfBytes()), $book, $user);
        $second = $this->pipeline()->receive($this->makeUpload('copy.pdf', $this->pdfBytes()), $book, $user);

        $this->assertFalse($second->ok);
        $this->assertSame($book->id, $second->duplicateOf);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM book_files'));
    }

    public function testATextFileIsIndexedForSearch(): void
    {
        [$book, $user] = $this->subject();

        $result = $this->pipeline()->receive(
            $this->makeUpload('notes.txt', 'The quick brown fox jumps over the lazy dog.'),
            $book,
            $user
        );

        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertStringContainsString(
            'quick brown fox',
            (string) $this->db->scalar('SELECT content FROM book_texts WHERE book_id = ?', [$book->id])
        );
    }

    public function testTheFirstFileOnARecordBecomesThePrimaryOne(): void
    {
        [$book, $user] = $this->subject();

        $this->pipeline()->receive($this->makeUpload('book.pdf', $this->pdfBytes('one')), $book, $user);
        $this->pipeline()->receive($this->makeUpload('other.pdf', $this->pdfBytes('two')), $book, $user);

        $primaries = $this->db->select('SELECT is_primary FROM book_files ORDER BY id');

        $this->assertSame(1, (int) $primaries[0]['is_primary']);
        $this->assertSame(0, (int) $primaries[1]['is_primary']);
    }
}
