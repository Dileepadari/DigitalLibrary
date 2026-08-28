<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class ReviewTest extends DatabaseTestCase
{
    public function testAMemberRatesABook(): void
    {
        $this->makeBook('Meditations');
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $response = $this->post('/books/meditations/reviews', [
            'rating' => '5',
            'body'   => 'Worth reading twice.',
        ]);

        $this->assertRedirectedTo('/books/meditations', $response);

        $row = $this->db->first('SELECT rating, body, status FROM reviews');

        $this->assertSame(5, (int) $row['rating']);
        $this->assertSame('Worth reading twice.', $row['body']);
        $this->assertSame('visible', $row['status']);
    }

    public function testTheBookCarriesTheAverage(): void
    {
        $bookId = $this->makeBook('Meditations');

        foreach ([['asha', 5], ['rahul', 4], ['meera', 3]] as [$username, $rating]) {
            $user = $this->makeUser($username);
            $this->signIn($user['email']);
            $this->post('/books/meditations/reviews', ['rating' => (string) $rating]);
        }

        $row = $this->db->first('SELECT rating_average, rating_count FROM books WHERE id = ?', [$bookId]);

        $this->assertSame('4.00', (string) $row['rating_average']);
        $this->assertSame(3, (int) $row['rating_count']);

        // The template breaks that sentence over three lines, so compare it
        // with the whitespace collapsed.
        $this->assertStringContainsString(
            'from 3 reviews',
            $this->flatten($this->get('/books/meditations')->body())
        );
    }

    public function testARatingHasToBeOneToFive(): void
    {
        $this->makeBook('Meditations');
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->post('/books/meditations/reviews', ['rating' => '9']);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM reviews'));
        $this->assertStringContainsString('one star to five', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testOneReviewPerPersonPerBook(): void
    {
        $this->makeBook('Meditations');
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->post('/books/meditations/reviews', ['rating' => '5', 'body' => 'First thoughts.']);
        $this->post('/books/meditations/reviews', ['rating' => '3', 'body' => 'On reflection.']);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM reviews'));

        $row = $this->db->first('SELECT rating, body FROM reviews');
        $this->assertSame(3, (int) $row['rating']);
        $this->assertSame('On reflection.', $row['body']);
    }

    public function testAnUnconfirmedAccountCannotReview(): void
    {
        $this->makeBook('Meditations');
        $user = $this->makeUser('asha', 'member', false);
        $this->signIn($user['email']);

        $this->post('/books/meditations/reviews', ['rating' => '5']);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM reviews'));
    }

    public function testAGuestSeesReviewsButCannotWriteOne(): void
    {
        $this->makeBook('Meditations');
        $author = $this->makeUser('asha');
        $this->signIn($author['email']);
        $this->post('/books/meditations/reviews', ['rating' => '4', 'body' => 'A steady companion.']);

        $this->post('/logout');
        $body = $this->get('/books/meditations')->body();

        $this->assertStringContainsString('A steady companion.', $body);
        $this->assertStringContainsString('to review this book', $body);
        $this->assertRedirectedTo('/login', $this->post('/books/meditations/reviews', ['rating' => '5']));
    }

    public function testTakingBackYourOwnReview(): void
    {
        $this->makeBook('Meditations');
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);
        $this->post('/books/meditations/reviews', ['rating' => '5']);

        $id = (int) $this->db->scalar('SELECT id FROM reviews');
        $this->post('/reviews/' . $id . '/delete');

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM reviews'));
        $this->assertSame(0, (int) $this->db->scalar('SELECT rating_count FROM books'));
    }

    public function testYouCannotDeleteSomeoneElsesReview(): void
    {
        $this->makeBook('Meditations');
        $author = $this->makeUser('asha');
        $this->signIn($author['email']);
        $this->post('/books/meditations/reviews', ['rating' => '5']);
        $id = (int) $this->db->scalar('SELECT id FROM reviews');

        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);
        $this->post('/reviews/' . $id . '/delete');

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM reviews'));
    }

    public function testHelpfulVotesAreCountedAndToggled(): void
    {
        $this->makeBook('Meditations');
        $author = $this->makeUser('asha');
        $this->signIn($author['email']);
        $this->post('/books/meditations/reviews', ['rating' => '5', 'body' => 'Useful.']);
        $id = (int) $this->db->scalar('SELECT id FROM reviews');

        $reader = $this->makeUser('rahul');
        $this->signIn($reader['email']);
        $this->post('/reviews/' . $id . '/helpful');
        $this->assertSame(1, (int) $this->db->scalar('SELECT helpful_count FROM reviews WHERE id = ?', [$id]));

        $this->post('/reviews/' . $id . '/helpful');
        $this->assertSame(0, (int) $this->db->scalar('SELECT helpful_count FROM reviews WHERE id = ?', [$id]));
    }

    public function testYouCannotVoteForYourOwnReview(): void
    {
        $this->makeBook('Meditations');
        $author = $this->makeUser('asha');
        $this->signIn($author['email']);
        $this->post('/books/meditations/reviews', ['rating' => '5']);
        $id = (int) $this->db->scalar('SELECT id FROM reviews');

        $this->post('/reviews/' . $id . '/helpful');

        $this->assertSame(0, (int) $this->db->scalar('SELECT helpful_count FROM reviews WHERE id = ?', [$id]));
        $this->assertStringContainsString('your own review', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testALibrarianHidesAReviewAndItLeavesTheAverage(): void
    {
        $bookId = $this->makeBook('Meditations');
        $author = $this->makeUser('asha');
        $this->signIn($author['email']);
        $this->post('/books/meditations/reviews', ['rating' => '1', 'body' => 'Unpleasant remarks.']);
        $id = (int) $this->db->scalar('SELECT id FROM reviews');

        $second = $this->makeUser('rahul');
        $this->signIn($second['email']);
        $this->post('/books/meditations/reviews', ['rating' => '5']);

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/reviews/' . $id . '/moderate', ['status' => 'hidden', 'reason' => 'Personal abuse.']);

        $this->assertSame('hidden', $this->db->scalar('SELECT status FROM reviews WHERE id = ?', [$id]));
        $this->assertSame('5.00', (string) $this->db->scalar('SELECT rating_average FROM books WHERE id = ?', [$bookId]));
        $this->assertSame(1, (int) $this->db->scalar('SELECT rating_count FROM books WHERE id = ?', [$bookId]));

        // The person who wrote it is told, and still sees it.
        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'review.hidden'",
                [$author['id']]
            )
        );

        $this->signIn($author['email']);
        $this->assertStringContainsString('Unpleasant remarks.', $this->get('/books/meditations')->body());

        // Nobody else does.
        $this->signIn($second['email']);
        $this->assertStringNotContainsString('Unpleasant remarks.', $this->get('/books/meditations')->body());
    }

    public function testHidingNeedsAReason(): void
    {
        $this->makeBook('Meditations');
        $author = $this->makeUser('asha');
        $this->signIn($author['email']);
        $this->post('/books/meditations/reviews', ['rating' => '2']);
        $id = (int) $this->db->scalar('SELECT id FROM reviews');

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/reviews/' . $id . '/moderate', ['status' => 'hidden', 'reason' => '']);

        $this->assertSame('visible', $this->db->scalar('SELECT status FROM reviews WHERE id = ?', [$id]));
    }

    public function testAMemberCannotModerate(): void
    {
        $this->makeBook('Meditations');
        $author = $this->makeUser('asha');
        $this->signIn($author['email']);
        $this->post('/books/meditations/reviews', ['rating' => '2']);
        $id = (int) $this->db->scalar('SELECT id FROM reviews');

        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);
        $response = $this->post('/reviews/' . $id . '/moderate', ['status' => 'hidden', 'reason' => 'Because.']);

        $this->assertSame(403, $response->status());
        $this->assertSame('visible', $this->db->scalar('SELECT status FROM reviews WHERE id = ?', [$id]));
    }

    public function testTheBrowsePageCanSortByRating(): void
    {
        $this->makeBook('Poorly Rated');
        $this->makeBook('Well Rated');

        $user = $this->makeUser('asha');
        $this->signIn($user['email']);
        $this->post('/books/poorly-rated/reviews', ['rating' => '1']);
        $this->post('/books/well-rated/reviews', ['rating' => '5']);

        $body = $this->get('/books?sort=rating')->body();

        $this->assertLessThan(
            strpos($body, 'Poorly Rated'),
            strpos($body, 'Well Rated'),
            'The better rated book should come first.'
        );
    }
}
