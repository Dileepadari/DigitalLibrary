<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

/**
 * The join between the two halves of the library: someone asks for a book,
 * someone else adds it, and everyone who asked hears about it without a person
 * having to remember to tell them.
 */
final class RequestFulfilmentTest extends DatabaseTestCase
{
    /** @return array{request: int, asker: int, voter: int} */
    private function wantedBook(string $title = 'Dune'): array
    {
        $asker = $this->makeUser('asha');
        $voter = $this->makeUser('rahul');
        $id = $this->makeRequest($title, $asker['id'], ['author' => 'Frank Herbert']);

        $this->signIn($voter['email']);
        $this->post('/requests/' . $id . '/vote');

        return ['request' => $id, 'asker' => $asker['id'], 'voter' => $voter['id']];
    }

    public function testTheFormPrefillsFromTheRequest(): void
    {
        $wanted = $this->wantedBook();
        $member = $this->makeUser('meera');
        $this->signIn($member['email']);

        $body = $this->get('/books/new?request=' . $wanted['request'])->body();

        $this->assertStringContainsString('Answering a request', $body);
        $this->assertStringContainsString('value="Dune"', $body);
        $this->assertStringContainsString('Frank Herbert', $body);
        $this->assertStringContainsString('name="request_id" value="' . $wanted['request'] . '"', $body);
    }

    public function testALibrariansAnswerFulfilsItImmediately(): void
    {
        $wanted = $this->wantedBook();
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->post('/books', [
            'title'      => 'Dune',
            'licence'    => 'public_domain',
            'request_id' => (string) $wanted['request'],
        ]);

        $row = $this->db->first(
            'SELECT status, fulfilled_by_book_id FROM book_requests WHERE id = ?',
            [$wanted['request']]
        );

        $this->assertSame('fulfilled', $row['status']);
        $this->assertNotNull($row['fulfilled_by_book_id']);
        $this->assertSame(
            2,
            (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE type = 'request.fulfilled'"),
            'Both the asker and the voter should be told.'
        );
    }

    public function testAMembersAnswerWaitsForReviewBeforeItCounts(): void
    {
        $wanted = $this->wantedBook();
        $member = $this->makeUser('meera');
        $this->signIn($member['email']);

        $this->upload(
            '/books',
            ['book_file' => $this->makeUpload('dune.pdf', $this->pdfBytes('dune'))],
            [
                'title'      => 'Dune',
                'licence'    => 'public_domain',
                'request_id' => (string) $wanted['request'],
            ]
        );

        // Nothing has happened to the request yet: a reviewer has not looked.
        $this->assertSame(
            'open',
            $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$wanted['request']])
        );
        $told = (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE type = 'request.fulfilled'");

        $this->assertSame(0, $told);

        $id = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'approve']);

        $row = $this->db->first(
            'SELECT status, fulfilled_by_book_id FROM book_requests WHERE id = ?',
            [$wanted['request']]
        );

        $this->assertSame('fulfilled', $row['status']);
        $this->assertNotNull($row['fulfilled_by_book_id']);
        $this->assertSame(
            2,
            (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE type = 'request.fulfilled'")
        );
    }

    public function testARejectedAnswerLeavesTheRequestOpen(): void
    {
        $wanted = $this->wantedBook();
        $member = $this->makeUser('meera');
        $this->signIn($member['email']);

        $this->upload(
            '/books',
            ['book_file' => $this->makeUpload('dune.pdf', $this->pdfBytes('dune'))],
            ['title' => 'Dune', 'licence' => 'public_domain', 'request_id' => (string) $wanted['request']]
        );

        $id = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', [
            'decision'      => 'reject',
            'canned_reason' => 'Poor scan quality',
        ]);

        $this->assertSame(
            'open',
            $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$wanted['request']])
        );
    }

    public function testARecordWithNoFileIsQueuedToo(): void
    {
        $wanted = $this->wantedBook();
        $member = $this->makeUser('meera');
        $this->signIn($member['email']);

        $this->post('/books', [
            'title'      => 'Dune',
            'licence'    => 'public_domain',
            'request_id' => (string) $wanted['request'],
        ]);

        $row = $this->db->first('SELECT subject_type, status FROM moderation_requests ORDER BY id DESC LIMIT 1');

        $this->assertSame('book_record', $row['subject_type']);
        $this->assertSame('pending', $row['status']);

        $id = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'approve']);

        $this->assertSame("published", $this->db->scalar('SELECT status FROM books WHERE title = ?', ['Dune']));
        $this->assertSame(
            'fulfilled',
            $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$wanted['request']])
        );
    }

    public function testRejectingARecordMarksItRejectedRatherThanLeavingItPending(): void
    {
        $member = $this->makeUser('meera');
        $this->signIn($member['email']);
        $this->post('/books', ['title' => 'Something Unwanted', 'licence' => 'unknown']);

        $id = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'reject', 'canned_reason' => 'Spam']);

        $this->assertSame(
            'rejected',
            $this->db->scalar('SELECT status FROM books WHERE title = ?', ['Something Unwanted'])
        );
    }

    public function testTheFulfillerIsCredited(): void
    {
        $wanted = $this->wantedBook();
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->post('/books', [
            'title'      => 'Dune',
            'licence'    => 'public_domain',
            'request_id' => (string) $wanted['request'],
        ]);

        $this->assertGreaterThan(
            0,
            (int) $this->db->scalar('SELECT reputation FROM users WHERE id = ?', [$librarian['id']])
        );
    }

    public function testAFulfilledRequestCannotBeFulfilledTwice(): void
    {
        $wanted = $this->wantedBook();
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->post('/books', ['title' => 'Dune', 'licence' => 'public_domain',
            'request_id' => (string) $wanted['request']]);
        $this->makeBook('Dune Again');
        $this->post('/requests/' . $wanted['request'], ['action' => 'fulfil', 'slug' => 'dune-again']);

        $notifications = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM notifications WHERE type = 'request.fulfilled'"
        );

        $this->assertSame(2, $notifications, 'Nobody should be told twice.');
    }

    public function testTheRequestPageLinksToTheBookThatAnsweredIt(): void
    {
        $wanted = $this->wantedBook();
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/books', ['title' => 'Dune', 'licence' => 'public_domain',
            'request_id' => (string) $wanted['request']]);

        $body = $this->get('/requests/' . $wanted['request'])->body();

        $this->assertStringContainsString('Answered by', $body);
        $this->assertStringContainsString('/books/dune', $body);
    }
}
