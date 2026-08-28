<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\BookRepository;
use App\Repositories\UserRepository;
use App\Services\ModerationService;
use App\Services\ReputationService;
use App\Services\UploadPipeline;
use App\Support\ReputationAction;
use Tests\DatabaseTestCase;

final class ReputationTest extends DatabaseTestCase
{
    private function reputation(): ReputationService
    {
        return $this->kernel()->container()->get(ReputationService::class);
    }

    public function testAnAwardAddsPointsAndAnEvent(): void
    {
        $user = $this->makeUser('asha');

        $this->reputation()->award($user['id'], ReputationAction::ReviewWritten, 'review', 1);

        $this->assertSame(2, (int) $this->db->scalar('SELECT reputation FROM users WHERE id = ?', [$user['id']]));

        $row = $this->db->first('SELECT action, points, subject_type FROM reputation_events');
        $this->assertSame('review.written', $row['action']);
        $this->assertSame(2, (int) $row['points']);
        $this->assertSame('review', $row['subject_type']);
    }

    public function testTheSameSubjectIsOnlyPaidOnce(): void
    {
        $user = $this->makeUser('asha');

        $this->reputation()->award($user['id'], ReputationAction::UploadAccepted, 'book_file', 7);
        $this->reputation()->award($user['id'], ReputationAction::UploadAccepted, 'book_file', 7);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM reputation_events'));
        $this->assertSame(5, (int) $this->db->scalar('SELECT reputation FROM users WHERE id = ?', [$user['id']]));
    }

    public function testAnAwardWithNoSubjectIsCountedEveryTime(): void
    {
        $user = $this->makeUser('asha');

        $this->reputation()->award($user['id'], ReputationAction::ReviewHelpful);
        $this->reputation()->award($user['id'], ReputationAction::ReviewHelpful);

        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM reputation_events'));
    }

    public function testRevokingTakesThePointsBackAndKeepsTheHistory(): void
    {
        $user = $this->makeUser('asha');

        $this->reputation()->award($user['id'], ReputationAction::ReviewWritten, 'review', 3);
        $this->reputation()->revoke($user['id'], ReputationAction::ReviewWritten, 'review', 3);

        $this->assertSame(0, (int) $this->db->scalar('SELECT reputation FROM users WHERE id = ?', [$user['id']]));
        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM reputation_events'));
        $this->assertSame(-2, (int) $this->db->scalar('SELECT points FROM reputation_events ORDER BY id DESC LIMIT 1'));
    }

    public function testReputationNeverGoesBelowZero(): void
    {
        $user = $this->makeUser('asha');

        $this->reputation()->revoke($user['id'], ReputationAction::UploadAccepted, 'book_file', 1);

        $this->assertSame(0, (int) $this->db->scalar('SELECT reputation FROM users WHERE id = ?', [$user['id']]));
    }

    public function testABadgeArrivesWithTheEventThatEarnsIt(): void
    {
        $user = $this->makeUser('asha');

        $this->reputation()->award($user['id'], ReputationAction::ReviewWritten, 'review', 1);

        $this->assertSame(
            'first-review',
            $this->db->scalar(
                'SELECT b.`key` FROM user_badges ub INNER JOIN badges b ON b.id = ub.badge_id WHERE ub.user_id = ?',
                [$user['id']]
            )
        );

        // And the person is told about it.
        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'badge.awarded'",
                [$user['id']]
            )
        );
    }

    public function testABadgeIsAwardedOnce(): void
    {
        $user = $this->makeUser('asha');

        for ($i = 1; $i <= 3; $i++) {
            $this->reputation()->award($user['id'], ReputationAction::ReviewWritten, 'review', $i);
        }

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM user_badges WHERE user_id = ?', [$user['id']]));
    }

    public function testAHigherBadgeArrivesAtItsThreshold(): void
    {
        $user = $this->makeUser('asha');

        for ($i = 1; $i <= 10; $i++) {
            $this->reputation()->award($user['id'], ReputationAction::ReviewWritten, 'review', $i);
        }

        $keys = array_column(
            $this->db->select(
                'SELECT b.`key` FROM user_badges ub INNER JOIN badges b ON b.id = ub.badge_id WHERE ub.user_id = ?
                 ORDER BY b.threshold',
                [$user['id']]
            ),
            'key'
        );

        $this->assertSame(['first-review', 'ten-reviews'], $keys);
    }

    public function testAnAcceptedUploadPaysItsAuthorAndTheReviewer(): void
    {
        $uploader = $this->makeUser('asha');
        $container = $this->kernel()->container();
        $bookId = $this->makeBook('A Contributed Book', ['status' => 'pending', 'added_by' => $uploader['id']]);
        $book = $container->get(BookRepository::class)->findById($bookId);
        $user = $container->get(UserRepository::class)->findById($uploader['id']);

        $this->assertNotNull($book);
        $this->assertNotNull($user);

        $result = $container->get(UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('paid')),
            $book,
            $user
        );
        $this->assertNotNull($result->file);
        $container->get(ModerationService::class)->submitUpload($book, $result->file, $user, false);

        $id = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'approve']);

        $this->assertSame(
            5,
            (int) $this->db->scalar('SELECT reputation FROM users WHERE id = ?', [$uploader['id']]),
            'The uploader gets the points for the upload.'
        );
        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT reputation FROM users WHERE id = ?', [$librarian['id']]),
            'The reviewer gets a point for the decision.'
        );
        $this->assertSame(
            'first-upload',
            $this->db->scalar(
                'SELECT b.`key` FROM user_badges ub INNER JOIN badges b ON b.id = ub.badge_id WHERE ub.user_id = ?',
                [$uploader['id']]
            )
        );
    }

    public function testAHelpfulVotePaysTheAuthorOncePerVoter(): void
    {
        $this->makeBook('Meditations');
        $author = $this->makeUser('asha');
        $this->signIn($author['email']);
        $this->post('/books/meditations/reviews', ['rating' => '5', 'body' => 'Useful.']);
        $id = (int) $this->db->scalar('SELECT id FROM reviews');

        $reader = $this->makeUser('rahul');
        $this->signIn($reader['email']);
        $this->post('/reviews/' . $id . '/helpful');
        $this->post('/reviews/' . $id . '/helpful');
        $this->post('/reviews/' . $id . '/helpful');

        // Two points for writing it, one for the first helpful vote from that
        // person, and no more however often they change their mind.
        $this->assertSame(3, (int) $this->db->scalar('SELECT reputation FROM users WHERE id = ?', [$author['id']]));
    }

    public function testTheLeaderboardRanksByReputation(): void
    {
        $quiet = $this->makeUser('quiet');
        $busy = $this->makeUser('busy');
        $nobody = $this->makeUser('nobody');

        $this->reputation()->award($quiet['id'], ReputationAction::ReviewWritten, 'review', 1);
        $this->reputation()->award($busy['id'], ReputationAction::RequestFulfilled, 'book_request', 1);

        $body = $this->get('/contributors')->body();

        $this->assertStringContainsString('busy', $body);
        $this->assertStringContainsString('quiet', $body);
        $this->assertLessThan(strpos($body, 'quiet'), strpos($body, 'busy'));
        $this->assertStringNotContainsString('@nobody', $body, 'Someone with no points is not a contributor yet.');
        $this->assertGreaterThan(0, $nobody['id']);
    }

    public function testTheProfileShowsBadgesAndATally(): void
    {
        $user = $this->makeUser('asha');
        $this->reputation()->award($user['id'], ReputationAction::ReviewWritten, 'review', 1);

        $body = $this->flatten($this->get('/u/asha')->body());

        $this->assertStringContainsString('Said something', $body);
        $this->assertStringContainsString('Wrote a review', $body);
        $this->assertStringContainsString('1 times', $body);
    }

    public function testTheBadgeListIsOnTheContributorsPage(): void
    {
        $body = $this->get('/contributors')->body();

        $this->assertStringContainsString('Shelf filler', $body);
        $this->assertStringContainsString('upload.accepted', $body);
    }
}
