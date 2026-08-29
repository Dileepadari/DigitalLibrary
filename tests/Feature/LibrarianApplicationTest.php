<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class LibrarianApplicationTest extends DatabaseTestCase
{
    private const STATEMENT = 'I have uploaded twenty scans and would like to help review what other people send in.';

    public function testAMemberApplies(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $response = $this->post('/apply', ['statement' => self::STATEMENT]);

        $this->assertRedirectedTo('/apply', $response);

        $row = $this->db->first('SELECT user_id, status FROM librarian_applications');
        $this->assertSame($member['id'], (int) $row['user_id']);
        $this->assertSame('pending', $row['status']);
    }

    public function testAShortStatementIsRefused(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $this->post('/apply', ['statement' => 'Please']);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM librarian_applications'));
    }

    public function testYouCannotApplyTwiceAtOnce(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);

        $this->post('/apply', ['statement' => self::STATEMENT]);
        $this->post('/apply', ['statement' => self::STATEMENT]);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM librarian_applications'));
        $this->assertStringContainsString('waiting already', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testALibrarianHasNothingToApplyFor(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->post('/apply', ['statement' => self::STATEMENT]);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM librarian_applications'));
    }

    public function testOnlyAnAdminDecides(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/apply', ['statement' => self::STATEMENT]);
        $id = (int) $this->db->scalar('SELECT id FROM librarian_applications');

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->assertSame(403, $this->get('/admin/applications')->status());
        $this->assertSame(403, $this->post('/admin/applications/' . $id, ['decision' => 'approve'])->status());
        $this->assertSame('member', $this->db->scalar('SELECT role FROM users WHERE id = ?', [$member['id']]));
    }

    public function testApprovingMakesThemALibrarian(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/apply', ['statement' => self::STATEMENT]);
        $id = (int) $this->db->scalar('SELECT id FROM librarian_applications');

        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        $this->assertStringContainsString('asha', $this->get('/admin/applications')->body());

        $this->post('/admin/applications/' . $id, ['decision' => 'approve']);

        $this->assertSame('librarian', $this->db->scalar('SELECT role FROM users WHERE id = ?', [$member['id']]));
        $status = $this->db->scalar('SELECT status FROM librarian_applications WHERE id = ?', [$id]);

        $this->assertSame('approved', $status);
        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'librarian.approved'",
                [$member['id']]
            )
        );
        $this->assertSame(
            1,
            (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'librarian.approved'")
        );

        // And the new librarian can open the queue.
        $this->signIn($member['email']);
        $this->assertSame(200, $this->get('/librarian/queue')->status());
    }

    public function testDecliningLeavesThemAMember(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/apply', ['statement' => self::STATEMENT]);
        $id = (int) $this->db->scalar('SELECT id FROM librarian_applications');

        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);
        $this->post('/admin/applications/' . $id, ['decision' => 'reject']);

        $this->assertSame('member', $this->db->scalar('SELECT role FROM users WHERE id = ?', [$member['id']]));
        $status = $this->db->scalar('SELECT status FROM librarian_applications WHERE id = ?', [$id]);

        $this->assertSame('rejected', $status);

        // And they can apply again.
        $this->signIn($member['email']);
        $this->post('/apply', ['statement' => self::STATEMENT]);
        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM librarian_applications'));
    }

    public function testTheSettingsPageOffersTheApplicationToMembersOnly(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->assertStringContainsString('Apply to be a librarian', $this->get('/me/settings')->body());

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->assertStringNotContainsString('Apply to be a librarian', $this->get('/me/settings')->body());
    }
}
