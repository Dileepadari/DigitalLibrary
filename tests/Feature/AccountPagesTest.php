<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class AccountPagesTest extends DatabaseTestCase
{
    public function testAProfileIsPublic(): void
    {
        $this->makeUser('asha');

        $response = $this->get('/u/asha');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Asha', $response->body());
        $this->assertStringContainsString('Member', $response->body());
    }

    public function testAProfileNeverShowsTheEmailAddress(): void
    {
        $this->makeUser('asha');

        $this->assertStringNotContainsString('asha@example.com', $this->get('/u/asha')->body());
    }

    public function testAnUnknownProfileIs404(): void
    {
        $this->assertSame(404, $this->get('/u/nobody')->status());
    }

    public function testSettingsNeedASession(): void
    {
        $this->assertRedirectedTo('/login', $this->get('/me/settings'));
    }

    public function testSettingsShowTheAccountAndItsPermissions(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $body = $this->get('/me/settings')->body();

        $this->assertStringContainsString('asha@example.com', $body);
        $this->assertStringContainsString('book.upload', $body);
        $this->assertStringNotContainsString('user.manage', $body);
    }

    public function testTheProfileCanBeEdited(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $response = $this->post('/me/settings', ['name' => 'Asha R', 'bio' => 'Reads history.']);

        $this->assertRedirectedTo('/me/settings', $response);

        $row = $this->db->first('SELECT name, bio FROM users WHERE id = ?', [$user['id']]);

        $this->assertSame('Asha R', $row['name']);
        $this->assertSame('Reads history.', $row['bio']);
    }

    public function testChangingThePasswordNeedsTheCurrentOne(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->post('/me/password', [
            'current_password'      => 'not-my-password',
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertArrayHasKey('current_password', $_SESSION['_flash']['errors']);

        $this->post('/logout');
        $this->signIn($user['email'], $user['password']);
    }

    public function testThePasswordCanBeChanged(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->post('/me/password', [
            'current_password'      => $user['password'],
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->post('/logout');
        $this->signIn($user['email'], 'a-brand-new-password');

        $this->assertSame($user['id'], $_SESSION['user_id'] ?? null);
    }

    public function testTheVerificationLinkConfirmsTheAddress(): void
    {
        $this->makeUser('founder', 'admin');

        $this->post('/register', [
            'name'                  => 'Asha Rao',
            'username'              => 'asha',
            'email'                 => 'asha@example.com',
            'password'              => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $path = $this->lastMailPath('/verify-email/');
        $this->assertNotNull($path, 'No confirmation link was written to the mail log.');

        $response = $this->get($path);

        $this->assertRedirectedTo('/', $response);
        $this->assertNotNull(
            $this->db->scalar('SELECT email_verified_at FROM users WHERE username = ?', ['asha'])
        );
    }

    public function testTheVerificationNoticeWarnsAnUnconfirmedAccount(): void
    {
        $user = $this->makeUser('asha', 'member', false);
        $this->signIn($user['email']);

        $this->assertStringContainsString('Confirm your email', $this->get('/')->body());
        $this->assertSame(200, $this->get('/verify-email')->status());
    }

    public function testAConfirmedAccountIsSentAwayFromTheNotice(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->assertRedirectedTo('/', $this->get('/verify-email'));
    }
}
