<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\BookRepository;
use Tests\DatabaseTestCase;

final class BookRequestTest extends DatabaseTestCase
{
    public function testTheListIsPublic(): void
    {
        $asker = $this->makeUser('asha');
        $this->makeRequest('The Mahabharata', $asker['id'], ['author' => 'Vyasa']);

        $body = $this->get('/requests')->body();

        $this->assertStringContainsString('The Mahabharata', $body);
        $this->assertStringContainsString('Vyasa', $body);
    }

    public function testAMemberAsksForABook(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $response = $this->post('/requests', [
            'title'  => 'Dune',
            'author' => 'Frank Herbert',
            'isbn'   => '978-0-441-01359-3',
            'note'   => 'The 1965 original, not the film tie-in.',
        ]);

        $row = $this->db->first('SELECT * FROM book_requests WHERE title = ?', ['Dune']);

        $this->assertNotNull($row);
        $this->assertRedirectedTo('/requests/' . $row['id'], $response);
        $this->assertSame('Frank Herbert', $row['author']);
        $this->assertSame('9780441013593', $row['isbn'], 'The ISBN should be stored as digits.');
        $this->assertSame('open', $row['status']);
        $this->assertSame($user['id'], (int) $row['requester_id']);
    }

    public function testAskingForABookCountsAsWantingIt(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);
        $this->post('/requests', ['title' => 'Dune']);

        $row = $this->db->first('SELECT id, vote_count FROM book_requests WHERE title = ?', ['Dune']);

        $this->assertSame(1, (int) $row['vote_count']);
        $this->assertSame(
            1,
            (int) $this->db->scalar(
                'SELECT COUNT(*) FROM book_request_votes WHERE request_id = ? AND user_id = ?',
                [$row['id'], $user['id']]
            )
        );
    }

    public function testATitleIsRequired(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->post('/requests', ['title' => '']);

        $this->assertArrayHasKey('title', $_SESSION['_flash']['errors']);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM book_requests'));
    }

    public function testAnUnconfirmedAccountCannotAsk(): void
    {
        $user = $this->makeUser('asha', 'member', false);
        $this->signIn($user['email']);

        $this->assertRedirectedTo('/verify-email', $this->post('/requests', ['title' => 'Dune']));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM book_requests'));
    }

    public function testAGuestCannotAsk(): void
    {
        $this->assertRedirectedTo('/login', $this->post('/requests', ['title' => 'Dune']));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM book_requests'));
    }

    public function testVotingIsAToggle(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        $voter = $this->makeUser('rahul');
        $this->signIn($voter['email']);

        $this->post('/requests/' . $id . '/vote');
        $this->assertSame(2, (int) $this->db->scalar('SELECT vote_count FROM book_requests WHERE id = ?', [$id]));

        $this->post('/requests/' . $id . '/vote');
        $this->assertSame(1, (int) $this->db->scalar('SELECT vote_count FROM book_requests WHERE id = ?', [$id]));
    }

    public function testOnePersonCountsOnce(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        // The person who asked already voted; pressing again takes it back
        // rather than adding a second.
        $this->signIn($asker['email']);
        $this->post('/requests/' . $id . '/vote');

        $this->assertSame(0, (int) $this->db->scalar('SELECT vote_count FROM book_requests WHERE id = ?', [$id]));
    }

    public function testTheListIsOrderedByDemand(): void
    {
        $asker = $this->makeUser('asha');
        $quiet = $this->makeRequest('Nobody Wants This', $asker['id']);
        $popular = $this->makeRequest('Everybody Wants This', $asker['id']);

        foreach (['rahul', 'meera', 'kabir'] as $username) {
            $voter = $this->makeUser($username);
            $this->signIn($voter['email']);
            $this->post('/requests/' . $popular . '/vote');
        }

        $body = $this->get('/requests')->body();

        $this->assertLessThan(
            strpos($body, 'Nobody Wants This'),
            strpos($body, 'Everybody Wants This'),
            'The most wanted book should be at the top.'
        );
    }

    public function testClaimingSaysSomeoneIsOnIt(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        $finder = $this->makeUser('rahul');
        $this->signIn($finder['email']);
        $this->post('/requests/' . $id, ['action' => 'claim']);

        $row = $this->db->first('SELECT status, claimed_by FROM book_requests WHERE id = ?', [$id]);

        $this->assertSame('claimed', $row['status']);
        $this->assertSame($finder['id'], (int) $row['claimed_by']);
        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'request.claimed'",
                [$asker['id']]
            )
        );
    }

    public function testAClaimedRequestCannotBeClaimedAgain(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        $first = $this->makeUser('rahul');
        $this->signIn($first['email']);
        $this->post('/requests/' . $id, ['action' => 'claim']);

        $second = $this->makeUser('meera');
        $this->signIn($second['email']);
        $this->post('/requests/' . $id, ['action' => 'claim']);

        $this->assertSame(
            $first['id'],
            (int) $this->db->scalar('SELECT claimed_by FROM book_requests WHERE id = ?', [$id])
        );
    }

    public function testTheClaimerCanGiveItUp(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        $finder = $this->makeUser('rahul');
        $this->signIn($finder['email']);
        $this->post('/requests/' . $id, ['action' => 'claim']);
        $this->post('/requests/' . $id, ['action' => 'release']);

        $row = $this->db->first('SELECT status, claimed_by FROM book_requests WHERE id = ?', [$id]);

        $this->assertSame('open', $row['status']);
        $this->assertNull($row['claimed_by']);
    }

    public function testSomeoneElseCannotGiveUpYourClaim(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        $finder = $this->makeUser('rahul');
        $this->signIn($finder['email']);
        $this->post('/requests/' . $id, ['action' => 'claim']);

        $meddler = $this->makeUser('meera');
        $this->signIn($meddler['email']);
        $this->post('/requests/' . $id, ['action' => 'release']);

        $this->assertSame('claimed', $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$id]));
    }

    public function testTheAskerCanCloseTheirOwnRequest(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        $this->signIn($asker['email']);
        $this->post('/requests/' . $id, [
            'action' => 'close',
            'status' => 'duplicate',
            'reason' => 'Found it under another title.',
        ]);

        $row = $this->db->first('SELECT status, close_reason FROM book_requests WHERE id = ?', [$id]);

        $this->assertSame('duplicate', $row['status']);
        $this->assertSame('Found it under another title.', $row['close_reason']);
    }

    public function testAnotherMemberCannotCloseIt(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);
        $this->post('/requests/' . $id, ['action' => 'close', 'status' => 'rejected']);

        $this->assertSame('open', $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$id]));
    }

    public function testALibrarianCanCloseAndReopenIt(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Dune', $asker['id']);

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/requests/' . $id, [
            'action' => 'close',
            'status' => 'unavailable',
            'reason' => 'Still in copyright everywhere.',
        ]);

        $this->assertSame('unavailable', $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$id]));
        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'request.closed'",
                [$asker['id']]
            )
        );

        $this->post('/requests/' . $id, ['action' => 'reopen']);
        $this->assertSame('open', $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$id]));
    }

    public function testALibrarianLinksAnExistingRecord(): void
    {
        $asker = $this->makeUser('asha');
        $voter = $this->makeUser('rahul');
        $id = $this->makeRequest('Meditations', $asker['id']);

        $this->signIn($voter['email']);
        $this->post('/requests/' . $id . '/vote');

        $this->makeBook('Meditations');

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/requests/' . $id, ['action' => 'fulfil', 'slug' => 'meditations']);

        $row = $this->db->first('SELECT status, fulfilled_by_book_id FROM book_requests WHERE id = ?', [$id]);

        $this->assertSame('fulfilled', $row['status']);
        $this->assertNotNull($row['fulfilled_by_book_id']);

        // The asker and the voter both hear about it.
        $this->assertSame(
            2,
            (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE type = 'request.fulfilled'")
        );
    }

    public function testAMemberCannotLinkARecord(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Meditations', $asker['id']);
        $this->makeBook('Meditations');

        $this->signIn($asker['email']);
        $this->post('/requests/' . $id, ['action' => 'fulfil', 'slug' => 'meditations']);

        $this->assertSame('open', $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$id]));
    }

    public function testLinkingAnUnknownAddressChangesNothing(): void
    {
        $asker = $this->makeUser('asha');
        $id = $this->makeRequest('Meditations', $asker['id']);

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/requests/' . $id, ['action' => 'fulfil', 'slug' => 'no-such-book']);

        $this->assertSame('open', $this->db->scalar('SELECT status FROM book_requests WHERE id = ?', [$id]));
    }

    public function testAnUnknownRequestIs404(): void
    {
        $this->assertSame(404, $this->get('/requests/999')->status());
    }
}
