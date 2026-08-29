<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class ReaderTest extends DatabaseTestCase
{
    public function testAGuestIsSentToSignIn(): void
    {
        $target = $this->makeReadableBook();
        $_SESSION = [];

        $this->assertRedirectedTo(
            '/login',
            $this->get('/books/' . $target['slug'] . '/read/' . $target['file_id'])
        );
    }

    public function testTheReaderOpensForAMember(): void
    {
        $target = $this->makeReadableBook();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $response = $this->get('/books/' . $target['slug'] . '/read/' . $target['file_id']);
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('data-reader', $body);
        $this->assertStringContainsString('data-kind="pdf"', $body);
        $this->assertStringContainsString('/files/' . $target['file_id'] . '?inline', $body);
        $this->assertStringContainsString('pdf.worker.min.mjs', $body);
        $this->assertStringContainsString('assets/js/reader.js', $body);
    }

    public function testATextFileGetsTheTextReader(): void
    {
        $target = $this->makeReadableBook('A Plain Text Book', 'txt');
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $body = $this->get('/books/' . $target['slug'] . '/read/' . $target['file_id'])->body();

        $this->assertStringContainsString('data-kind="text"', $body);
    }

    public function testTheFileHasToBelongToTheBookInTheAddress(): void
    {
        $target = $this->makeReadableBook();
        $other = $this->makeBook('A Different Book');
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $this->assertSame(
            404,
            $this->get('/books/a-different-book/read/' . $target['file_id'])->status(),
            'A file id from one book must not open under another book.'
        );
        $this->assertGreaterThan(0, $other);
    }

    public function testAQuarantinedFileCannotBeRead(): void
    {
        $uploader = $this->makeUser('asha');
        $container = $this->kernel()->container();
        $bookId = $this->makeBook('Waiting Book', ['status' => 'pending', 'added_by' => $uploader['id']]);
        $book = $container->get(\App\Repositories\BookRepository::class)->findById($bookId);
        $user = $container->get(\App\Repositories\UserRepository::class)->findById($uploader['id']);

        $this->assertNotNull($book);
        $this->assertNotNull($user);

        $result = $container->get(\App\Services\UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('waiting')),
            $book,
            $user
        );
        $this->assertNotNull($result->file);

        $stranger = $this->makeUser('rahul');
        $this->signIn($stranger['email']);
        $this->assertSame(404, $this->get('/books/waiting-book/read/' . $result->file->id)->status());

        // The uploader and a reviewer can look at their own.
        $this->signIn($uploader['email']);
        $this->assertSame(200, $this->get('/books/waiting-book/read/' . $result->file->id)->status());
    }

    public function testProgressIsSavedAndComesBack(): void
    {
        $target = $this->makeReadableBook();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $base = '/books/' . $target['slug'] . '/read/' . $target['file_id'];
        $response = $this->post($base . '/progress', ['position' => '42', 'percent' => '37']);

        $this->assertSame(200, $response->status());
        $this->assertSame(['ok' => true], json_decode($response->body(), true));

        $row = $this->db->first(
            'SELECT position, percent FROM reading_progress WHERE user_id = ? AND book_file_id = ?',
            [$member['id'], $target['file_id']]
        );

        $this->assertSame('42', $row['position']);
        $this->assertSame(37, (int) $row['percent']);

        // The reader picks it up on the next visit.
        $this->assertStringContainsString('data-position="42"', $this->get($base)->body());
    }

    public function testSavingAgainMovesTheSameRowRatherThanAddingOne(): void
    {
        $target = $this->makeReadableBook();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $base = '/books/' . $target['slug'] . '/read/' . $target['file_id'];
        $this->post($base . '/progress', ['position' => '10', 'percent' => '10']);
        $this->post($base . '/progress', ['position' => '20', 'percent' => '20']);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM reading_progress'));
        $this->assertSame('20', $this->db->scalar('SELECT position FROM reading_progress'));
    }

    public function testAPercentOutsideTheScaleIsClamped(): void
    {
        $target = $this->makeReadableBook();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $base = '/books/' . $target['slug'] . '/read/' . $target['file_id'];
        $this->post($base . '/progress', ['position' => '1', 'percent' => '900']);

        $this->assertSame(100, (int) $this->db->scalar('SELECT percent FROM reading_progress'));
    }

    public function testProgressNeedsAPosition(): void
    {
        $target = $this->makeReadableBook();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $response = $this->post(
            '/books/' . $target['slug'] . '/read/' . $target['file_id'] . '/progress',
            ['position' => '', 'percent' => '10']
        );

        $this->assertSame(422, $response->status());
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM reading_progress'));
    }

    public function testEveryoneKeepsTheirOwnPlace(): void
    {
        $target = $this->makeReadableBook();
        $base = '/books/' . $target['slug'] . '/read/' . $target['file_id'];

        $first = $this->makeUser('asha');
        $this->signIn($first['email']);
        $this->post($base . '/progress', ['position' => '11', 'percent' => '11']);

        $second = $this->makeUser('rahul');
        $this->signIn($second['email']);
        $this->post($base . '/progress', ['position' => '77', 'percent' => '77']);

        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM reading_progress'));
        $this->assertStringContainsString('data-position="77"', $this->get($base)->body());

        $this->signIn($first['email']);
        $this->assertStringContainsString('data-position="11"', $this->get($base)->body());
    }

    public function testTheHomePageOffersToCarryOn(): void
    {
        $target = $this->makeReadableBook();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $this->post('/books/' . $target['slug'] . '/read/' . $target['file_id'] . '/progress', [
            'position' => '5',
            'percent'  => '55',
        ]);

        $body = $this->get('/')->body();

        $this->assertStringContainsString('Carry on reading', $body);
        $this->assertStringContainsString('55% through', $body);
    }

    public function testBookmarksAreKeptPerPersonAndCanBeRemoved(): void
    {
        $target = $this->makeReadableBook();
        $base = '/books/' . $target['slug'] . '/read/' . $target['file_id'];

        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post($base . '/bookmarks', [
            'position' => '12',
            'label'    => 'Where it gets good',
            'note'     => 'Compare with chapter 2.',
        ]);

        $body = $this->get($base)->body();
        $this->assertStringContainsString('Where it gets good', $body);
        $this->assertStringContainsString('Compare with chapter 2.', $body);

        $id = (int) $this->db->scalar('SELECT id FROM bookmarks LIMIT 1');

        // Someone else cannot remove it, even knowing the id.
        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);
        $this->post($base . '/bookmarks/' . $id . '/delete');
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM bookmarks'));

        $this->signIn($member['email']);
        $this->post($base . '/bookmarks/' . $id . '/delete');
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM bookmarks'));
    }

    public function testABookmarkNeedsAPlace(): void
    {
        $target = $this->makeReadableBook();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $this->post('/books/' . $target['slug'] . '/read/' . $target['file_id'] . '/bookmarks', [
            'position' => '',
            'label'    => 'Nowhere',
        ]);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM bookmarks'));
    }

    public function testTheBookPageOffersToReadWhatItCan(): void
    {
        $target = $this->makeReadableBook();
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $body = $this->get('/books/' . $target['slug'])->body();

        $this->assertStringContainsString('Read PDF', $body);
        $this->assertStringContainsString('/read/' . $target['file_id'], $body);
    }
}
