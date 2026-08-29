<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class PasswordResetTest extends DatabaseTestCase
{
    public function testTheAnswerIsTheSameForAKnownAndAnUnknownAddress(): void
    {
        $this->makeUser('asha');

        $known = $this->post('/forgot-password', ['email' => 'asha@example.com']);
        $knownMessage = $_SESSION['_flash']['success'] ?? null;

        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);
        $unknownMessage = $_SESSION['_flash']['success'] ?? null;

        $this->assertRedirectedTo('/login', $known);
        $this->assertRedirectedTo('/login', $unknown);
        $this->assertSame($knownMessage, $unknownMessage);
    }

    public function testOnlyAKnownAddressGetsAToken(): void
    {
        $this->makeUser('asha');

        $this->post('/forgot-password', ['email' => 'nobody@example.com']);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM auth_tokens'));

        $this->post('/forgot-password', ['email' => 'asha@example.com']);
        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT COUNT(*) FROM auth_tokens WHERE type = ?', ['password_reset'])
        );
    }

    public function testTheLinkFromTheEmailChangesThePassword(): void
    {
        $user = $this->makeUser('asha');
        $this->post('/forgot-password', ['email' => $user['email']]);

        $path = $this->lastMailPath('/reset-password/');
        $this->assertNotNull($path, 'No reset link was written to the mail log.');

        $this->assertSame(200, $this->get($path)->status());

        $response = $this->post($path, [
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertRedirectedTo('/login', $response);

        $this->signIn($user['email'], 'a-brand-new-password');
        $this->assertSame($user['id'], $_SESSION['user_id'] ?? null);
    }

    public function testATokenWorksOnlyOnce(): void
    {
        $user = $this->makeUser('asha');
        $this->post('/forgot-password', ['email' => $user['email']]);
        $path = (string) $this->lastMailPath('/reset-password/');

        $this->post($path, [
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $second = $this->post($path, [
            'password'              => 'yet-another-password',
            'password_confirmation' => 'yet-another-password',
        ]);

        $this->assertRedirectedTo('/forgot-password', $second);
        $this->signIn($user['email'], 'a-brand-new-password');
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->makeUser('asha');

        $response = $this->post('/reset-password/' . str_repeat('a', 64), [
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertRedirectedTo('/forgot-password', $response);
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $user = $this->makeUser('asha');
        $this->post('/forgot-password', ['email' => $user['email']]);
        $path = (string) $this->lastMailPath('/reset-password/');

        $this->db->execute('UPDATE auth_tokens SET expires_at = ?', [gmdate('Y-m-d H:i:s', time() - 60)]);

        $response = $this->post($path, [
            'password'              => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $this->assertRedirectedTo('/forgot-password', $response);
        $this->signIn($user['email']);
    }
}
