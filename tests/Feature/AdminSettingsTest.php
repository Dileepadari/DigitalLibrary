<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\SettingsRepository;
use Tests\DatabaseTestCase;

final class AdminSettingsTest extends DatabaseTestCase
{
    /** @return array<string, mixed> the whole settings form, so a POST changes only what it means to */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'site_name'                        => 'Digital Library',
            'site_tagline'                     => 'A community library anyone can add to.',
            'registration_mode'                => 'open',
            'uploads_require_licence_evidence' => '1',
            'uploads_max_bytes'                => '209715200',
            'uploads_default_quota'            => '2147483648',
            'features_reviews'                 => '1',
            'features_requests'                => '1',
            'site_maintenance_message'         => '',
        ], $overrides);
    }

    public function testOnlyAnAdminReachesTheAdminArea(): void
    {
        $this->assertRedirectedTo('/login', $this->get('/admin'));

        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->assertSame(403, $this->get('/admin')->status());

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->assertSame(403, $this->get('/admin/settings')->status());

        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);
        $this->assertSame(200, $this->get('/admin')->status());
    }

    public function testTheDashboardCountsWhatIsThere(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->makeBook('A Book');
        $this->makeRequest('Something Wanted', $admin['id']);
        $this->signIn($admin['email']);

        $body = $this->flatten($this->get('/admin')->body());

        $this->assertStringContainsString('Members', $body);
        $this->assertStringContainsString('Books', $body);
        $this->assertStringContainsString('The queue', $body);
        $this->assertStringContainsString('Storage', $body);
    }

    public function testChangingASettingTakesEffectAndIsAudited(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        $this->post('/admin/settings', $this->form(['site_name' => 'Cherry Community Library']));

        $this->assertSame(
            '"Cherry Community Library"',
            $this->db->scalar("SELECT value FROM settings WHERE `key` = 'site.name'")
        );

        // Every page reads the setting, not the config default: the header on
        // an unrelated page is the honest check.
        $this->assertStringContainsString(
            '<span class="brand__name">Cherry Community Library</span>',
            $this->get('/books')->body()
        );

        $row = $this->db->first("SELECT before_state, after_state FROM audit_logs WHERE action = 'settings.updated'");
        $this->assertNotNull($row);
        $this->assertStringContainsString('Digital Library', (string) $row['before_state']);
        $this->assertStringContainsString('Cherry Community Library', (string) $row['after_state']);
    }

    public function testNothingIsWrittenWhenNothingChanges(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        $this->post('/admin/settings', $this->form());

        $this->assertSame(
            0,
            (int) $this->db->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.updated'")
        );
    }

    public function testClosingRegistrationStopsNewAccounts(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);
        $this->post('/admin/settings', $this->form(['registration_mode' => 'closed']));
        $this->post('/logout');

        $this->assertSame(403, $this->get('/register')->status());
        $this->post('/register', [
            'name'                  => 'Asha Rao',
            'username'              => 'asha',
            'email'                 => 'asha@example.com',
            'password'              => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testTurningOffRequestsHidesThem(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);

        $this->assertStringContainsString('Requests', $this->get('/')->body());

        $this->post('/admin/settings', $this->form(['features_requests' => '']));

        $this->assertStringNotContainsString('>Requests<', $this->get('/')->body());
    }

    public function testTurningOffReviewsHidesTheReviewSection(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->makeBook('Meditations');
        $this->signIn($admin['email']);

        $this->assertStringContainsString('Reviews', $this->get('/books/meditations')->body());

        $this->post('/admin/settings', $this->form(['features_reviews' => '']));

        $this->assertStringNotContainsString('id="reviews"', $this->get('/books/meditations')->body());
    }

    public function testTheUploadLimitComesFromTheSetting(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);
        $this->post('/admin/settings', $this->form(['uploads_max_bytes' => '100']));

        $container = $this->kernel()->container();
        $this->assertSame(100, (int) $container->get(SettingsRepository::class)->get('uploads.max_bytes'));

        $bookId = $this->makeBook('A Book', ['added_by' => $admin['id']]);
        $book = $container->get(\App\Repositories\BookRepository::class)->findById($bookId);
        $user = $container->get(\App\Repositories\UserRepository::class)->findById($admin['id']);
        $this->assertNotNull($book);
        $this->assertNotNull($user);

        $result = $container->get(\App\Services\UploadPipeline::class)->receive(
            $this->makeUpload('book.pdf', $this->pdfBytes('too big now')),
            $book,
            $user
        );

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('limit is', (string) $result->error);
    }

    public function testANewAccountGetsTheConfiguredQuota(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $this->signIn($admin['email']);
        $this->post('/admin/settings', $this->form(['uploads_default_quota' => '1048576']));
        $this->post('/logout');

        $this->post('/register', [
            'name'                  => 'Asha Rao',
            'username'              => 'asha',
            'email'                 => 'asha@example.com',
            'password'              => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $this->assertSame(
            1048576,
            (int) $this->db->scalar('SELECT storage_quota FROM users WHERE username = ?', ['asha'])
        );
    }

    public function testMaintenanceModeClosesTheSiteToEveryoneButAdmins(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $member = $this->makeUser('asha');

        $this->signIn($admin['email']);
        $this->post('/admin/settings', $this->form([
            'site_maintenance'         => '1',
            'site_maintenance_message' => 'Moving the shelves around.',
        ]));

        // The admin can still work.
        $this->assertSame(200, $this->get('/admin')->status());

        $this->signIn($member['email']);
        $response = $this->get('/books');

        $this->assertSame(503, $response->status());
        $this->assertStringContainsString('Moving the shelves around.', $response->body());
        $this->assertSame('600', $response->headers()['Retry-After']);

        // The health check still answers, or a monitor could not tell closed
        // from broken.
        $this->assertContains($this->get('/health')->status(), [200, 503]);
        $this->assertStringContainsString('"ok"', $this->get('/health')->body());
    }

    /**
     * A healthy install hides the checklist from visitors, but an admin still
     * needs it: it is the only place that reports storage and migrations.
     */
    public function testAnAdminStillSeesTheInstallPanelOnTheHomePage(): void
    {
        $admin = $this->makeUser('boss', 'admin');
        $member = $this->makeUser('asha');

        $this->signIn($member['email']);
        $this->assertStringNotContainsString('Install status', $this->get('/')->body());

        $this->signIn($admin['email']);
        $body = $this->get('/')->body();

        $this->assertStringContainsString('Install status', $body);

        foreach (['PHP', 'Database', 'Migrations', 'Storage'] as $check) {
            $this->assertStringContainsString($check, $body);
        }
    }
}
