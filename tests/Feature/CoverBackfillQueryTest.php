<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\BookFileRepository;
use Tests\DatabaseTestCase;

/**
 * The query behind `covers:generate`.
 *
 * It is reachable only from the CLI, so nothing exercised it and it shipped
 * broken: `GROUP BY f.book_id` while selecting non-aggregated columns is
 * rejected by MySQL's default sql_mode (only_full_group_by), so the command
 * died on any stock MySQL 8 with a SQLSTATE 42000.
 */
final class CoverBackfillQueryTest extends DatabaseTestCase
{
    private BookFileRepository $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = $this->kernel()->container()->get(BookFileRepository::class);
    }

    private function attachPdf(int $bookId, string $sha, int $uploadedBy): int
    {
        return $this->db->insert('book_files', [
            'book_id'       => $bookId,
            'storage_path'  => 'books/' . $sha . '.pdf',
            'original_name' => 'book.pdf',
            'format'        => 'pdf',
            'mime_type'     => 'application/pdf',
            'size_bytes'    => 1024,
            'sha256'        => $sha,
            'status'        => 'published',
            'uploaded_by'   => $uploadedBy,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testItRunsAtAllUnderTheDefaultSqlMode(): void
    {
        $account = $this->makeUser('asha');
        $bookId = $this->makeBook('Needs A Cover', ['added_by' => $account['id']]);
        $this->attachPdf($bookId, str_repeat('a', 64), (int) $account['id']);

        $rows = $this->files->pdfsWithoutCover();

        $this->assertNotEmpty($rows, 'A PDF on a book with no cover should be offered for backfill.');
        $this->assertSame($bookId, $rows[0]['book_id']);
    }

    public function testABookIsOfferedOnceEvenWithSeveralPdfs(): void
    {
        $account = $this->makeUser('asha');
        $bookId = $this->makeBook('Two Editions', ['added_by' => $account['id']]);
        $first = $this->attachPdf($bookId, str_repeat('b', 64), (int) $account['id']);
        $this->attachPdf($bookId, str_repeat('c', 64), (int) $account['id']);

        $rows = array_values(array_filter(
            $this->files->pdfsWithoutCover(),
            static fn (array $row): bool => $row['book_id'] === $bookId,
        ));

        $this->assertCount(1, $rows, 'One row per book, or the backfill renders the same book twice.');

        // Deterministically the earliest file, not whichever the engine happened
        // to pick. GROUP BY left this undefined even where it was accepted.
        $this->assertSame(str_repeat('b', 64), $rows[0]['sha256']);
        $this->assertStringContainsString(str_repeat('b', 64), $rows[0]['storage_path']);
        $this->assertGreaterThan(0, $first);
    }

    public function testABookThatAlreadyHasACoverIsLeftAlone(): void
    {
        $account = $this->makeUser('asha');
        $bookId = $this->makeBook('Already Covered', ['added_by' => $account['id']]);
        $this->attachPdf($bookId, str_repeat('d', 64), (int) $account['id']);
        $this->db->execute('UPDATE books SET cover_path = ? WHERE id = ?', ['covers/x.jpg', $bookId]);

        $ids = array_column($this->files->pdfsWithoutCover(), 'book_id');

        $this->assertNotContains($bookId, $ids);
    }
}
