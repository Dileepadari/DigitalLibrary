<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class TakedownTest extends DatabaseTestCase
{
    /** @return array<string, string> */
    private function notice(array $overrides = []): array
    {
        return array_merge([
            'claimant_name'  => 'Jane Rights',
            'claimant_email' => 'jane@example.com',
            'claimant_role'  => 'The publisher',
            'basis'          => 'We hold the exclusive rights to this edition and did not license it here.',
        ], $overrides);
    }

    public function testAnyoneCanSendANoticeWithoutAnAccount(): void
    {
        $this->makeBook('Meditations');

        $response = $this->post('/report', $this->notice(['book_slug' => 'meditations']));

        $this->assertRedirectedTo('/report', $response);

        $row = $this->db->first('SELECT claimant_name, status, book_id FROM takedowns');

        $this->assertSame('Jane Rights', $row['claimant_name']);
        $this->assertSame('open', $row['status']);
        $this->assertNotNull($row['book_id']);
    }

    public function testTheFormArrivesWithTheBookFilledIn(): void
    {
        $this->makeBook('Meditations');

        $body = $this->get('/report?book=meditations')->body();

        $this->assertStringContainsString('Meditations', $body);
        $this->assertStringContainsString('name="book_slug" value="meditations"', $body);
    }

    public function testANoticeNeedsAnExplanationAndAWayToReply(): void
    {
        $this->post('/report', $this->notice(['basis' => 'Take it down']));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM takedowns'));
        $this->assertArrayHasKey('basis', $_SESSION['_flash']['errors']);

        $this->post('/report', $this->notice(['claimant_email' => 'not-an-address']));
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM takedowns'));
    }

    public function testTheBookPageLinksToTheForm(): void
    {
        $this->makeBook('Meditations');

        $this->assertStringContainsString('/report?book=meditations', $this->get('/books/meditations')->body());
    }

    public function testOnlyAnAdminSeesTheConsole(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->assertSame(403, $this->get('/admin/takedowns')->status());
    }

    public function testUpholdingANoticeHidesTheBook(): void
    {
        $this->makeBook('Meditations');
        $this->post('/report', $this->notice(['book_slug' => 'meditations']));
        $id = (int) $this->db->scalar('SELECT id FROM takedowns');

        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);
        $this->post('/admin/takedowns/' . $id, [
            'decision' => 'upheld',
            'note'     => 'Rights confirmed with the publisher.',
        ]);

        $this->assertSame('hidden', $this->db->scalar('SELECT status FROM books WHERE slug = ?', ['meditations']));

        $notice = $this->db->first('SELECT status, outcome_note, handled_by FROM takedowns WHERE id = ?', [$id]);
        $this->assertSame('upheld', $notice['status']);
        $this->assertSame('Rights confirmed with the publisher.', $notice['outcome_note']);
        $this->assertSame($admin['id'], (int) $notice['handled_by']);

        // And it is off the shelves for everyone else.
        $this->post('/logout');
        $this->assertSame(404, $this->get('/books/meditations')->status());
    }

    public function testRejectingANoticeLeavesTheBookAlone(): void
    {
        $this->makeBook('Meditations');
        $this->post('/report', $this->notice(['book_slug' => 'meditations']));
        $id = (int) $this->db->scalar('SELECT id FROM takedowns');

        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);
        $this->post('/admin/takedowns/' . $id, ['decision' => 'rejected', 'note' => 'Public domain since 1935.']);

        $this->assertSame('published', $this->db->scalar('SELECT status FROM books WHERE slug = ?', ['meditations']));
        $this->assertSame('rejected', $this->db->scalar('SELECT status FROM takedowns WHERE id = ?', [$id]));
    }

    public function testADecisionIsAuditedAndCannotBeMadeTwice(): void
    {
        $this->makeBook('Meditations');
        $this->post('/report', $this->notice(['book_slug' => 'meditations']));
        $id = (int) $this->db->scalar('SELECT id FROM takedowns');

        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);
        $this->post('/admin/takedowns/' . $id, ['decision' => 'upheld', 'note' => 'Confirmed.']);
        $this->post('/admin/takedowns/' . $id, ['decision' => 'rejected', 'note' => 'Changed my mind.']);

        $this->assertSame('upheld', $this->db->scalar('SELECT status FROM takedowns WHERE id = ?', [$id]));
        $this->assertSame(
            1,
            (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'takedown.%'")
        );
    }

    public function testTheDashboardSaysHowLongNoticesHaveWaited(): void
    {
        $this->post('/report', $this->notice(['subject_url' => 'https://example.org/something']));
        $this->db->execute("UPDATE takedowns SET created_at = DATE_SUB(NOW(), INTERVAL 3 DAY)");

        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        // The sentence has a link in the middle of it, so compare the text.
        $body = $this->text($this->get('/admin')->body());

        $this->assertStringContainsString('1 takedown notice(s) waiting, the oldest for 3 days', $body);
    }
}
