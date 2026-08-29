<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\BookFileRepository;
use Tests\DatabaseTestCase;

/**
 * Searching the words inside a book, not only the words about it.
 */
final class SearchInsideTest extends DatabaseTestCase
{
    private function indexText(int $bookId, string $text): void
    {
        $this->kernel()->container()->get(BookFileRepository::class)->storeText($bookId, $text);
    }

    public function testAWordFromInsideTheBookFindsIt(): void
    {
        $found = $this->makeBook('An Untitled Volume');
        $this->makeBook('Something Else Entirely');

        $this->indexText($found, 'The Trichonephila spider weaves a golden web across the path.');

        $body = $this->get('/search?q=trichonephila')->body();

        $this->assertStringContainsString('An Untitled Volume', $body);
        $this->assertStringNotContainsString('Something Else Entirely', $body);
    }

    public function testTheApiSearchesInsideToo(): void
    {
        $book = $this->makeBook('An Untitled Volume');
        $this->indexText($book, 'A passage mentioning astrolabes and nothing else of note.');

        $payload = json_decode($this->get('/api/v1/books?q=astrolabes')->body(), true);

        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame('An Untitled Volume', $payload['data'][0]['title']);
    }

    public function testAnUnpublishedBookStaysOutOfTheResults(): void
    {
        $hidden = $this->makeBook('Waiting For Review', ['status' => 'pending']);
        $this->indexText($hidden, 'The word quicksilver appears only in this waiting book.');

        $this->assertStringNotContainsString('Waiting For Review', $this->get('/search?q=quicksilver')->body());
    }

    public function testTextIsStoredWhenAFileIsUploaded(): void
    {
        $target = $this->makeReadableBook('A Text Book', 'txt');

        $stored = (string) $this->db->scalar(
            'SELECT content FROM book_texts WHERE book_id = ?',
            [$target['book_id']]
        );

        $this->assertStringContainsString('The text of A Text Book', $stored);
        $this->assertStringContainsString('A Text Book', $this->get('/search?q=text')->body());
    }

    public function testReindexingRebuildsWhatWasLost(): void
    {
        $target = $this->makeReadableBook('A Text Book', 'txt');
        $this->db->execute('DELETE FROM book_texts');

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM book_texts'));

        // What `search:reindex` does, without shelling out to the console.
        $container = $this->kernel()->container();
        $files = $container->get(BookFileRepository::class);
        $storage = $container->get(\App\Core\Storage::class);
        $extractor = $container->get(\App\Services\TextExtractor::class);

        foreach ($files->all() as $file) {
            $format = pathinfo($file['storage_path'], PATHINFO_EXTENSION);
            $extracted = $extractor->fromFile($storage->absolute($file['storage_path']), $format);

            if ($extracted['text'] !== null) {
                $files->storeTextForFile($file['id'], $extracted['text']);
            }
        }

        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT COUNT(*) FROM book_texts WHERE book_id = ?', [$target['book_id']])
        );
    }
}
