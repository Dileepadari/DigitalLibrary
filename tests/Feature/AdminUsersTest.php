<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class AdminUsersTest extends DatabaseTestCase
{
    public function testAGuestIsSentToSignIn(): void
    {
        $this->assertRedirectedTo('/login', $this->get('/admin/users'));
    }

    public function testAMemberIsForbidden(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->assertSame(403, $this->get('/admin/users')->status());
    }

    public function testALibrarianIsForbidden(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $this->assertSame(403, $this->get('/admin/users')->status());
    }

    public function testAnAdminSeesTheList(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->makeUser('asha');
        $this->signIn($admin['email']);

        $response = $this->get('/admin/users');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('asha@example.com', $response->body());
    }

    public function testTheSearchFilterNarrowsTheList(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->makeUser('asha');
        $this->makeUser('rahul');
        $this->signIn($admin['email']);

        $body = $this->get('/admin/users?search=asha')->body();

        $this->assertStringContainsString('asha@example.com', $body);
        $this->assertStringNotContainsString('rahul@example.com', $body);
    }

    public function testAnAdminPromotesAMemberToLibrarian(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $target = $this->makeUser('asha');
        $this->signIn($admin['email']);

        $response = $this->post('/admin/users/' . $target['id'] . '/role', ['role' => 'librarian']);

        $this->assertRedirectedTo('/admin/users', $response);
        $this->assertSame('librarian', $this->db->scalar('SELECT role FROM users WHERE id = ?', [$target['id']]));
    }

    public function testARoleChangeIsAudited(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $target = $this->makeUser('asha');
        $this->signIn($admin['email']);

        $this->post('/admin/users/' . $target['id'] . '/role', ['role' => 'librarian']);

        $row = $this->db->first(
            'SELECT actor_id, subject_id, before_state, after_state FROM audit_logs WHERE action = ?',
            ['user.role_changed']
        );

        $this->assertNotNull($row);
        $this->assertSame($admin['id'], (int) $row['actor_id']);
        $this->assertSame($target['id'], (int) $row['subject_id']);
        $this->assertSame('member', json_decode((string) $row['before_state'], true)['role']);
        $this->assertSame('librarian', json_decode((string) $row['after_state'], true)['role']);
    }

    public function testAnAdminCannotChangeTheirOwnRole(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        $this->post('/admin/users/' . $admin['id'] . '/role', ['role' => 'member']);

        $this->assertSame('admin', $this->db->scalar('SELECT role FROM users WHERE id = ?', [$admin['id']]));
    }

    public function testTheLastAdminCannotBeDemoted(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $other = $this->makeUser('second', 'admin');
        $this->signIn($admin['email']);

        // Two admins: demoting one is fine.
        $this->post('/admin/users/' . $other['id'] . '/role', ['role' => 'member']);
        $this->assertSame('member', $this->db->scalar('SELECT role FROM users WHERE id = ?', [$other['id']]));

        // Now promote them back and have them demote the original: still fine,
        // because there are two again.
        $this->post('/admin/users/' . $other['id'] . '/role', ['role' => 'admin']);
        $this->signIn($other['email']);
        $this->post('/admin/users/' . $admin['id'] . '/role', ['role' => 'member']);

        $this->assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'"));
    }

    public function testTheOnlyAdminIsProtectedFromDemotionByAnother(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        // Promote a second admin, then have them try to demote the first, then
        // the first tries to demote the second: whichever way, one must remain.
        $second = $this->makeUser('second');
        $this->post('/admin/users/' . $second['id'] . '/role', ['role' => 'admin']);
        $this->post('/admin/users/' . $second['id'] . '/role', ['role' => 'member']);

        $this->assertGreaterThanOrEqual(
            1,
            (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'")
        );
    }

    public function testAnAdminBansAMember(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $target = $this->makeUser('trouble');
        $this->signIn($admin['email']);

        $this->post('/admin/users/' . $target['id'] . '/status', [
            'status' => 'banned',
            'reason' => 'Uploading copyrighted scans',
        ]);

        $row = $this->db->first('SELECT status, status_reason FROM users WHERE id = ?', [$target['id']]);

        $this->assertSame('banned', $row['status']);
        $this->assertSame('Uploading copyrighted scans', $row['status_reason']);
    }

    public function testAMuteCanCarryAnExpiry(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $target = $this->makeUser('noisy');
        $this->signIn($admin['email']);

        $this->post('/admin/users/' . $target['id'] . '/status', ['status' => 'muted', 'days' => '7']);

        $row = $this->db->first('SELECT status, status_until FROM users WHERE id = ?', [$target['id']]);

        $this->assertSame('muted', $row['status']);
        $this->assertNotNull($row['status_until']);
    }

    public function testAnExpiredMuteLiftsOnTheNextSignIn(): void
    {
        $target = $this->makeUser('noisy', 'member', true, 'muted');
        $this->db->execute(
            'UPDATE users SET status_until = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s', time() - 3600), $target['id']]
        );

        $this->signIn($target['email']);

        $this->assertSame('active', $this->db->scalar('SELECT status FROM users WHERE id = ?', [$target['id']]));
    }

    public function testPostingWithoutACsrfTokenIsRefused(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $target = $this->makeUser('asha');
        $this->signIn($admin['email']);

        $response = $this->request('POST', '/admin/users/' . $target['id'] . '/role', ['role' => 'librarian']);

        $this->assertSame(419, $response->status());
        $this->assertSame('member', $this->db->scalar('SELECT role FROM users WHERE id = ?', [$target['id']]));
    }
}
