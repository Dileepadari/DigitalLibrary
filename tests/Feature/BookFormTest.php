<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class BookFormTest extends DatabaseTestCase
{
    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'title'       => 'The Time Machine',
            'authors'     => 'H. G. Wells',
            'description' => 'A traveller goes forward.',
            'licence'     => 'public_domain',
            'language'    => 'en',
            'published_year' => '1895',
        ], $overrides);
    }

    public function testAGuestIsSentToSignIn(): void
    {
        $this->assertRedirectedTo('/login', $this->get('/books/new'));
    }

    public function testAMemberSeesTheFormAndItsReviewWarning(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $body = $this->get('/books/new')->body();

        $this->assertStringContainsString('Add a book', $body);
        $this->assertStringContainsString('A librarian reviews what you add', $body);
    }

    public function testAnUnconfirmedMemberIsSentToConfirmFirst(): void
    {
        $user = $this->makeUser('asha', 'member', false);
        $this->signIn($user['email']);

        $this->assertRedirectedTo('/verify-email', $this->get('/books/new'));
        $this->assertRedirectedTo('/verify-email', $this->post('/books', $this->form()));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM books'));
    }

    public function testAMembersSubmissionWaitsForReview(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $response = $this->post('/books', $this->form());

        // Straight to the page that tracks what happens to it next.
        $this->assertRedirectedTo('/me/submissions', $response);

        $row = $this->db->first(
            'SELECT status, added_by, published_at FROM books WHERE slug = ?',
            ['the-time-machine']
        );

        $this->assertSame('pending', $row['status']);
        $this->assertSame($user['id'], (int) $row['added_by']);
        $this->assertNull($row['published_at']);
        $this->assertSame(404, $this->get('/books/the-time-machine')->status());
    }

    public function testALibrariansSubmissionGoesStraightIn(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $response = $this->post('/books', $this->form());

        $this->assertRedirectedTo('/books/the-time-machine', $response);
        $this->assertSame(
            'published',
            $this->db->scalar('SELECT status FROM books WHERE slug = ?', ['the-time-machine'])
        );
    }

    public function testAuthorsAndTagsAreCreatedFromTheForm(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $this->post('/books', $this->form(['authors' => 'H. G. Wells, Someone Else', 'tags' => 'Classic, Airships']));

        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM book_authors'));
        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM book_tags'));
        $this->assertSame(
            'H. G. Wells',
            $this->db->scalar('SELECT name FROM authors ORDER BY id LIMIT 1')
        );
    }

    public function testALibrariansNewTagIsLiveAndAMembersWaits(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/books', $this->form(['tags' => 'Steampunk']));

        $this->assertSame('active', $this->db->scalar('SELECT status FROM tags WHERE slug = ?', ['steampunk']));

        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/books', $this->form(['title' => 'Another Book', 'tags' => 'Zeppelins']));

        $this->assertSame('pending', $this->db->scalar('SELECT status FROM tags WHERE slug = ?', ['zeppelins']));
        $this->assertStringNotContainsString('Zeppelins', $this->get('/tags')->body());
    }

    public function testAMissingTitleIsRejected(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $response = $this->post('/books', $this->form(['title' => '']));

        $this->assertRedirectedTo('/books/new', $response);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM books'));
        $this->assertArrayHasKey('title', $_SESSION['_flash']['errors']);
    }

    public function testAnInvalidSourceUrlIsRejected(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $this->post('/books', $this->form(['source_url' => 'not a url']));

        $this->assertArrayHasKey('source_url', $_SESSION['_flash']['errors']);
    }

    public function testAMemberCanEditTheirOwnRecordButNotAnotherPersons(): void
    {
        $asha = $this->makeUser('asha');
        $rahul = $this->makeUser('rahul');

        $this->makeBook('Ashas Record', ['added_by' => $asha['id'], 'status' => 'pending']);

        $this->signIn($asha['email']);
        $this->assertSame(200, $this->get('/books/ashas-record/edit')->status());

        $this->signIn($rahul['email']);
        $this->assertSame(403, $this->get('/books/ashas-record/edit')->status());
    }

    public function testALibrarianCanEditAnyRecord(): void
    {
        $asha = $this->makeUser('asha');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->makeBook('Ashas Record', ['added_by' => $asha['id']]);

        $this->signIn($librarian['email']);
        $response = $this->post('/books/ashas-record/edit', $this->form(['title' => 'A Better Title']));

        $this->assertRedirectedTo('/books/ashas-record', $response);
        $this->assertSame(
            'A Better Title',
            $this->db->scalar('SELECT title FROM books WHERE slug = ?', ['ashas-record'])
        );
    }

    public function testThePublicAddressSurvivesATitleChange(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->makeBook('Original Title');
        $this->signIn($librarian['email']);

        $this->post('/books/original-title/edit', $this->form(['title' => 'Renamed Entirely']));

        // The slug is part of every link that already exists to this record.
        $this->assertSame(200, $this->get('/books/original-title')->status());
        $this->assertSame(404, $this->get('/books/renamed-entirely')->status());
    }

    public function testAPendingRecordsAddressFollowsItsTitle(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->makeBook('Draft Title', ['status' => 'pending']);
        $this->signIn($librarian['email']);

        $this->post('/books/draft-title/edit', $this->form(['title' => 'Settled Title']));

        $this->assertSame(
            'settled-title',
            $this->db->scalar('SELECT slug FROM books WHERE title = ?', ['Settled Title'])
        );
    }

    public function testALibrarianPublishesAPendingRecord(): void
    {
        $this->makeBook('Waiting For Review', ['status' => 'pending']);
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $response = $this->post('/books/waiting-for-review/status', ['status' => 'published']);

        $this->assertRedirectedTo('/books/waiting-for-review', $response);

        $row = $this->db->first('SELECT status, published_at FROM books WHERE slug = ?', ['waiting-for-review']);

        $this->assertSame('published', $row['status']);
        $this->assertNotNull($row['published_at']);
        $this->assertStringContainsString('Waiting For Review', $this->get('/books')->body());
    }

    public function testAMemberCannotPublish(): void
    {
        $this->makeBook('Waiting For Review', ['status' => 'pending']);
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $response = $this->post('/books/waiting-for-review/status', ['status' => 'published']);
        $status = $this->db->scalar('SELECT status FROM books WHERE slug = ?', ['waiting-for-review']);

        $this->assertSame(403, $response->status());
        $this->assertSame('pending', $status);
    }

    public function testCreatingAndEditingAreAudited(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/books', $this->form());
        $this->post('/books/the-time-machine/edit', $this->form(['title' => 'The Time Machine']));

        $actions = array_column(
            $this->db->select("SELECT action FROM audit_logs WHERE action LIKE 'book.%' ORDER BY id"),
            'action'
        );

        $this->assertSame(['book.created', 'book.updated'], $actions);
    }
}
