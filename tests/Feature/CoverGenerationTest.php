<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Storage;
use App\Repositories\BookRepository;
use App\Repositories\UserRepository;
use App\Services\CoverGenerator;
use App\Services\UploadPipeline;
use Tests\DatabaseTestCase;

/**
 * Covers are made from page one of an uploaded PDF when the record has none.
 *
 * Rendering needs a tool PHP does not have on its own, so these skip themselves
 * on a host with no renderer rather than failing there.
 */
final class CoverGenerationTest extends DatabaseTestCase
{
    private CoverGenerator $covers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->covers = $this->kernel()->container()->get(CoverGenerator::class);

        if (!$this->covers->available()) {
            $this->markTestSkipped('No PDF renderer here: install poppler-utils, Ghostscript or Imagick.');
        }
    }

    /** @return array{book: \App\Models\Book, user: \App\Models\User} */
    private function subject(?string $coverPath = null): array
    {
        $account = $this->makeUser('asha');
        $bookId = $this->makeBook('A Book Needing A Cover', ['added_by' => $account['id']]);

        if ($coverPath !== null) {
            $this->db->execute('UPDATE books SET cover_path = ? WHERE id = ?', [$coverPath, $bookId]);
        }

        $container = $this->kernel()->container();
        $book = $container->get(BookRepository::class)->findById($bookId);
        $user = $container->get(UserRepository::class)->findById($account['id']);

        $this->assertNotNull($book);
        $this->assertNotNull($user);

        return ['book' => $book, 'user' => $user];
    }

    public function testTheGeneratorNamesTheRendererItWillUse(): void
    {
        $this->assertContains($this->covers->renderer(), ['imagick', 'pdftoppm', 'ghostscript']);
    }

    public function testUploadingAPdfMakesACover(): void
    {
        $subject = $this->subject();

        $result = $this->kernel()->container()->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('cover me')),
            $subject['book'],
            $subject['user']
        );

        $this->assertTrue($result->ok, (string) $result->error);

        $path = (string) $this->db->scalar('SELECT cover_path FROM books WHERE id = ?', [$subject['book']->id]);

        $this->assertStringStartsWith('covers/', $path);
        $this->assertStringEndsWith('.jpg', $path);

        $storage = $this->kernel()->container()->get(Storage::class);
        $this->assertTrue($storage->exists($path));

        $size = getimagesize($storage->absolute($path));
        $this->assertNotFalse($size, 'The cover should be a readable image.');
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertLessThanOrEqual(600, $size[0], 'A cover is a thumbnail, not the page.');
        $this->assertGreaterThan(100, $size[1]);
    }

    public function testTheCoverIsNamedAfterTheFileItCameFrom(): void
    {
        $subject = $this->subject();

        $result = $this->kernel()->container()->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('named')),
            $subject['book'],
            $subject['user']
        );

        $this->assertNotNull($result->file);

        $path = (string) $this->db->scalar('SELECT cover_path FROM books WHERE id = ?', [$subject['book']->id]);

        $this->assertStringContainsString($result->file->sha256, $path);
    }

    public function testAnExistingCoverIsLeftAlone(): void
    {
        $subject = $this->subject('covers/kept/by-hand.jpg');

        $this->kernel()->container()->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('leave it')),
            $subject['book'],
            $subject['user']
        );

        $this->assertSame(
            'covers/kept/by-hand.jpg',
            $this->db->scalar('SELECT cover_path FROM books WHERE id = ?', [$subject['book']->id])
        );
    }

    public function testANonPdfUploadMakesNoCover(): void
    {
        $subject = $this->subject();

        $this->kernel()->container()->get(UploadPipeline::class)->receive(
            $this->makeUpload('notes.txt', 'Just some words.'),
            $subject['book'],
            $subject['user']
        );

        $this->assertNull($this->db->scalar('SELECT cover_path FROM books WHERE id = ?', [$subject['book']->id]));
    }

    public function testSomethingThatIsNotAPdfRendersNothing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'notapdf');
        file_put_contents((string) $path, 'this is not a pdf at all');

        $this->assertNull($this->covers->fromPdf((string) $path, str_repeat('a', 64)));

        @unlink((string) $path);
    }

    public function testAMissingFileRendersNothing(): void
    {
        $this->assertNull($this->covers->fromPdf('/no/such/file.pdf', str_repeat('b', 64)));
    }

    public function testTheCoverIsServedForAPublishedBook(): void
    {
        $subject = $this->subject();

        $this->kernel()->container()->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('served')),
            $subject['book'],
            $subject['user']
        );

        $response = $this->get('/covers/' . $subject['book']->id);

        $this->assertSame(200, $response->status());
        $this->assertSame('image/jpeg', $response->headers()['Content-Type']);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $this->bodyOf($response), 'That is not a JPEG.');
    }

    public function testTheCoverOfAnUnpublishedBookIsHidden(): void
    {
        $account = $this->makeUser('asha');
        $bookId = $this->makeBook('Waiting Book', ['status' => 'pending', 'added_by' => $account['id']]);

        $container = $this->kernel()->container();
        $book = $container->get(BookRepository::class)->findById($bookId);
        $user = $container->get(UserRepository::class)->findById($account['id']);
        $this->assertNotNull($book);
        $this->assertNotNull($user);

        $container->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('hidden')),
            $book,
            $user
        );

        $this->assertSame(404, $this->get('/covers/' . $bookId)->status());

        // The person who submitted it, and a reviewer, can see it.
        $this->signIn($account['email']);
        $this->assertSame(200, $this->get('/covers/' . $bookId)->status());

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->assertSame(200, $this->get('/covers/' . $bookId)->status());
    }

    public function testABookWithNoCoverIs404(): void
    {
        $subject = $this->subject();

        $this->assertSame(404, $this->get('/covers/' . $subject['book']->id)->status());
        $this->assertSame(404, $this->get('/covers/999999')->status());
    }

    public function testTheBookPageShowsTheCoverRatherThanTheLetter(): void
    {
        $subject = $this->subject();

        $this->kernel()->container()->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('shown')),
            $subject['book'],
            $subject['user']
        );

        $body = $this->get('/books/' . $subject['book']->slug)->body();

        $this->assertStringContainsString('/covers/' . $subject['book']->id, $body);
        $this->assertStringContainsString('alt="Cover of A Book Needing A Cover"', $body);
    }
}
