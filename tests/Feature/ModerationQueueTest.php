<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Storage;
use App\Repositories\BookRepository;
use App\Repositories\ModerationRepository;
use App\Repositories\UserRepository;
use App\Services\ModerationService;
use App\Support\ModerationType;
use App\Support\RejectionReason;
use Tests\DatabaseTestCase;

final class ModerationQueueTest extends DatabaseTestCase
{
    /** Signs in as a member and uploads a file, returning the queue item id. */
    private function memberUploads(string $username = 'asha', string $marker = 'one'): int
    {
        $member = $this->makeUser($username);
        $this->signIn($member['email']);

        $bookId = $this->makeBook(
            'A Book By ' . $username . ' ' . $marker,
            ['status' => 'pending', 'added_by' => $member['id']]
        );
        $book = $this->kernel()->container()->get(BookRepository::class)->findById($bookId);
        $this->assertNotNull($book);

        $response = $this->upload(
            '/books/' . $book->slug . '/files',
            ['book_file' => $this->makeUpload('book.pdf', $this->pdfBytes($marker))]
        );

        $this->assertRedirectedTo('/me/submissions', $response);

        return (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
    }

    public function testAMembersUploadWaitsInTheQueue(): void
    {
        $id = $this->memberUploads();

        $row = $this->db->first(
            'SELECT subject_type, status, submitter_id FROM moderation_requests WHERE id = ?',
            [$id]
        );

        $this->assertSame(ModerationType::BookUpload->value, $row['subject_type']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('quarantined', $this->db->scalar('SELECT status FROM book_files LIMIT 1'));
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM books WHERE status = 'published'"));
    }

    public function testALibrariansUploadSkipsTheQueue(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $bookId = $this->makeBook('Straight In', ['status' => 'pending', 'added_by' => $librarian['id']]);
        $book = $this->kernel()->container()->get(BookRepository::class)->findById($bookId);
        $this->assertNotNull($book);

        $response = $this->upload(
            '/books/' . $book->slug . '/files',
            ['book_file' => $this->makeUpload('book.pdf', $this->pdfBytes('lib'))]
        );

        $this->assertRedirectedTo('/books/' . $book->slug, $response);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM moderation_requests'));
        $this->assertSame('published', $this->db->scalar('SELECT status FROM book_files LIMIT 1'));
        $this->assertSame('published', $this->db->scalar('SELECT status FROM books WHERE id = ?', [$bookId]));
    }

    public function testTheReviewScreenShowsTheFileAndTheSubmitter(): void
    {
        $id = $this->memberUploads();

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $response = $this->get('/librarian/queue/' . $id);
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('The file', $body);
        $this->assertStringContainsString('book.pdf', $body);
        $this->assertStringContainsString('quarantined', $body);
        $this->assertStringContainsString('asha', $body);
        $this->assertStringContainsString('SHA-256', $body);
    }

    public function testAMemberCannotOpenTheQueue(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $this->assertSame(403, $this->get('/librarian/queue')->status());
    }

    public function testALibrarianSeesTheQueueOldestFirst(): void
    {
        $this->memberUploads('asha', 'one');
        $this->memberUploads('rahul', 'two');

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $body = $this->get('/librarian/queue')->body();

        $this->assertStringContainsString('A Book By asha', $body);
        $this->assertStringContainsString('A Book By rahul', $body);
        $this->assertLessThan(
            strpos($body, 'A Book By rahul'),
            strpos($body, 'A Book By asha'),
            'The queue should be oldest first.'
        );
    }

    public function testClaimingLocksTheItemForOneReviewer(): void
    {
        $id = $this->memberUploads();

        $first = $this->makeUser('libby', 'librarian');
        $second = $this->makeUser('linus', 'librarian');

        $this->signIn($first['email']);
        $this->post('/librarian/queue/' . $id . '/claim');

        $this->assertSame('under_review', $this->db->scalar(
            'SELECT status FROM moderation_requests WHERE id = ?',
            [$id]
        ));
        $this->assertSame($first['id'], (int) $this->db->scalar(
            'SELECT assignee_id FROM moderation_requests WHERE id = ?',
            [$id]
        ));

        $this->signIn($second['email']);
        $response = $this->post('/librarian/queue/' . $id . '/claim');

        $this->assertRedirectedTo('/librarian/queue', $response);
        $this->assertStringContainsString('Someone else', (string) ($_SESSION['_flash']['error'] ?? ''));
        $this->assertSame($first['id'], (int) $this->db->scalar(
            'SELECT assignee_id FROM moderation_requests WHERE id = ?',
            [$id]
        ));
    }

    public function testAnExpiredClaimFreesTheItem(): void
    {
        $id = $this->memberUploads();

        $first = $this->makeUser('libby', 'librarian');
        $this->signIn($first['email']);
        $this->post('/librarian/queue/' . $id . '/claim');

        $this->db->execute(
            'UPDATE moderation_requests SET claimed_until = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s', time() - 60), $id]
        );

        $second = $this->makeUser('linus', 'librarian');
        $this->signIn($second['email']);
        $this->post('/librarian/queue/' . $id . '/claim');

        $this->assertSame($second['id'], (int) $this->db->scalar(
            'SELECT assignee_id FROM moderation_requests WHERE id = ?',
            [$id]
        ));
    }

    public function testApprovingPublishesTheFileAndTheRecord(): void
    {
        $id = $this->memberUploads();
        $uploaderId = (int) $this->db->scalar('SELECT uploaded_by FROM book_files LIMIT 1');
        $size = (int) $this->db->scalar('SELECT size_bytes FROM book_files LIMIT 1');

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $response = $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'approve']);

        $this->assertRedirectedTo('/librarian/queue', $response);

        $file = $this->db->first('SELECT status, storage_path FROM book_files LIMIT 1');
        $this->assertSame('published', $file['status']);
        $this->assertStringStartsWith('library/', (string) $file['storage_path']);

        $storage = $this->kernel()->container()->get(Storage::class);
        $this->assertTrue($storage->exists((string) $file['storage_path']), 'The file should be in the library.');
        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM books WHERE status = 'published'"));

        $user = $this->kernel()->container()->get(UserRepository::class)->findById($uploaderId);
        $this->assertNotNull($user);
        $this->assertSame($size, $user->storageUsed, 'Published bytes count against the quota.');
        $this->assertGreaterThan(0, $user->reputation);
    }

    public function testApprovalNotifiesTheSubmitter(): void
    {
        $id = $this->memberUploads();
        $submitterId = (int) $this->db->scalar('SELECT submitter_id FROM moderation_requests WHERE id = ?', [$id]);

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'approve']);

        // An approval can bring a badge with it, so look for the one we mean
        // rather than whichever arrived first.
        $row = $this->db->first(
            "SELECT type, title FROM notifications WHERE user_id = ? AND type = 'moderation.approved'",
            [$submitterId]
        );

        $this->assertNotNull($row);
        $this->assertStringContainsString('Approved', (string) $row['title']);
    }

    public function testRejectingNeedsAReason(): void
    {
        $id = $this->memberUploads();

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'reject']);

        $this->assertSame('under_review', $this->db->scalar(
            'SELECT status FROM moderation_requests WHERE id = ?',
            [$id]
        ));
        $this->assertStringContainsString('needs a reason', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testRejectingLeavesTheFileOutOfTheLibrary(): void
    {
        $id = $this->memberUploads();

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', [
            'decision'      => 'reject',
            'canned_reason' => 'Poor scan quality',
        ]);

        $file = $this->db->first('SELECT status, storage_path, rejected_at FROM book_files LIMIT 1');

        $this->assertSame('rejected', $file['status']);
        $this->assertStringStartsWith('quarantine/', (string) $file['storage_path']);
        $this->assertNotNull($file['rejected_at']);
        $this->assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM books WHERE status = 'published'"));
    }

    public function testACopyrightRejectionIsAStrike(): void
    {
        $id = $this->memberUploads();
        $uploaderId = (int) $this->db->scalar('SELECT uploaded_by FROM book_files LIMIT 1');

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', [
            'decision'      => 'reject',
            'canned_reason' => RejectionReason::COPYRIGHT,
        ]);

        $this->assertSame(1, (int) $this->db->scalar('SELECT strikes FROM users WHERE id = ?', [$uploaderId]));
    }

    public function testAnOrdinaryRejectionIsNotAStrike(): void
    {
        $id = $this->memberUploads();
        $uploaderId = (int) $this->db->scalar('SELECT uploaded_by FROM book_files LIMIT 1');

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', [
            'decision'      => 'reject',
            'canned_reason' => 'Poor scan quality',
        ]);

        $this->assertSame(0, (int) $this->db->scalar('SELECT strikes FROM users WHERE id = ?', [$uploaderId]));
    }

    public function testAReviewerCannotDecideTheirOwnSubmission(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $container = $this->kernel()->container();
        $user = $container->get(UserRepository::class)->findById($librarian['id']);
        $this->assertNotNull($user);

        $moderation = $container->get(ModerationService::class);
        $id = $moderation->open(ModerationType::CategoryProposal, null, 'Something of mine', [], $user);

        $request = $container->get(ModerationRepository::class)->findById($id);
        $this->assertNotNull($request);

        $result = $moderation->decide($request, true, '', $user);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('your own submission', $result['message']);
    }

    public function testAnAdminMayDecideTheirOwnAndTheAuditSaysSo(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $container = $this->kernel()->container();
        $user = $container->get(UserRepository::class)->findById($admin['id']);
        $this->assertNotNull($user);

        $moderation = $container->get(ModerationService::class);
        $id = $moderation->open(ModerationType::CategoryProposal, null, 'Mine to decide', [], $user);
        $request = $container->get(ModerationRepository::class)->findById($id);
        $this->assertNotNull($request);

        $this->assertTrue($moderation->decide($request, true, '', $user)['ok']);

        $after = json_decode(
            (string) $this->db->scalar("SELECT after_state FROM audit_logs WHERE action = 'moderation.approved'"),
            true
        );

        $this->assertTrue($after['own_submission']);
    }

    public function testChangesRequestedGoesBackToTheSubmitterAndCanBeResubmitted(): void
    {
        $id = $this->memberUploads();
        $submitterEmail = 'asha@example.com';

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', [
            'decision' => 'changes',
            'reason'   => 'The scan is upside down from page 40.',
        ]);

        $this->assertSame(
            'changes_requested',
            $this->db->scalar('SELECT status FROM moderation_requests WHERE id = ?', [$id])
        );

        $this->signIn($submitterEmail);
        $body = $this->get('/me/submissions/' . $id)->body();
        $this->assertStringContainsString('upside down', $body);

        $this->post('/me/submissions/' . $id, ['action' => 'resubmit']);

        $this->assertSame('pending', $this->db->scalar('SELECT status FROM moderation_requests WHERE id = ?', [$id]));
    }

    public function testASubmitterCanWithdraw(): void
    {
        $id = $this->memberUploads();

        $this->post('/me/submissions/' . $id, ['action' => 'withdraw']);

        $this->assertSame('withdrawn', $this->db->scalar('SELECT status FROM moderation_requests WHERE id = ?', [$id]));
        $this->assertSame('rejected', $this->db->scalar('SELECT status FROM book_files LIMIT 1'));
    }

    public function testOneSubmitterCannotTouchAnothersSubmission(): void
    {
        $id = $this->memberUploads('asha');
        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);

        $this->assertSame(404, $this->get('/me/submissions/' . $id)->status());
        $this->assertSame(404, $this->post('/me/submissions/' . $id, ['action' => 'withdraw'])->status());
        $this->assertSame('pending', $this->db->scalar('SELECT status FROM moderation_requests WHERE id = ?', [$id]));
    }

    public function testCommentsNotifyTheOtherSide(): void
    {
        $id = $this->memberUploads();
        $submitterId = (int) $this->db->scalar('SELECT submitter_id FROM moderation_requests WHERE id = ?', [$id]);

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/comment', ['body' => 'Which edition is this?']);

        $this->assertSame(1, (int) $this->db->scalar(
            'SELECT COUNT(*) FROM moderation_comments WHERE request_id = ?',
            [$id]
        ));
        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'moderation.comment'",
                [$submitterId]
            )
        );
    }

    public function testEveryTransitionIsRecorded(): void
    {
        $id = $this->memberUploads();

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'approve']);

        $events = array_column(
            $this->db->select('SELECT to_status FROM moderation_events WHERE request_id = ? ORDER BY id', [$id]),
            'to_status'
        );

        $this->assertSame(['pending', 'under_review', 'approved'], $events);
    }

    public function testACategoryProposalArrivesInTheSameQueue(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/categories/propose', ['name' => 'Gardening']);

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $body = $this->get('/librarian/queue')->body();

        $this->assertStringContainsString('Category: Gardening', $body);
        $this->assertStringContainsString('Category proposal', $body);
    }

    public function testDecidingACategoryFromTheQueueActivatesIt(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/categories/propose', ['name' => 'Gardening']);

        $id = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $id . '/claim');
        $this->post('/librarian/queue/' . $id . '/decide', ['decision' => 'approve']);

        $this->assertSame('active', $this->db->scalar('SELECT status FROM categories WHERE slug = ?', ['gardening']));
    }
}
