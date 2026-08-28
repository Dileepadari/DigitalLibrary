<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class LoginTest extends DatabaseTestCase
{
    public function testTheFormRenders(): void
    {
        $response = $this->get('/login');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Sign in', $response->body());
    }

    public function testCorrectCredentialsSignIn(): void
    {
        $user = $this->makeUser('asha');

        $response = $this->post('/login', ['email' => $user['email'], 'password' => $user['password']]);

        $this->assertRedirectedTo('/', $response);
        $this->assertSame($user['id'], $_SESSION['user_id'] ?? null);
    }

    public function testTheHeaderShowsTheSignedInAccount(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $body = $this->get('/')->body();

        $this->assertStringContainsString('asha', $body);
        $this->assertStringContainsString('Sign out', $body);
    }

    public function testAWrongPasswordIsRejected(): void
    {
        $user = $this->makeUser('asha');

        $this->post('/login', ['email' => $user['email'], 'password' => 'not-the-password']);

        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT COUNT(*) FROM login_attempts WHERE successful = 0')
        );
    }

    public function testAnUnknownEmailIsRejectedTheSameWay(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'whatever-it-is']);

        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertStringContainsString('do not match', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testSixFailedAttemptsAreThrottled(): void
    {
        $user = $this->makeUser('asha');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user['email'], 'password' => 'wrong-password-here']);
        }

        // The sixth is refused before the password is even checked.
        $this->post('/login', ['email' => $user['email'], 'password' => $user['password']]);

        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertStringContainsString('Too many attempts', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testASuccessfulSignInClearsTheFailureCount(): void
    {
        $user = $this->makeUser('asha');

        $this->post('/login', ['email' => $user['email'], 'password' => 'wrong-password-here']);
        $this->signIn($user['email']);

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM login_attempts WHERE successful = 0')
        );
    }

    public function testABannedAccountCannotSignIn(): void
    {
        $user = $this->makeUser('trouble', 'member', true, 'banned');

        $this->post('/login', ['email' => $user['email'], 'password' => $user['password']]);

        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertStringContainsString('suspended', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testBanningASignedInAccountEndsTheSessionOnTheNextRequest(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->db->execute('UPDATE users SET status = ? WHERE id = ?', ['banned', $user['id']]);

        $body = $this->get('/')->body();

        $this->assertStringContainsString('Sign in', $body);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testSigningOut(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $response = $this->post('/logout');

        $this->assertRedirectedTo('/', $response);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testSignInIsAudited(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs WHERE action = ?', ['auth.sign_in'])
        );
    }

    public function testASignedInUserIsSentAwayFromTheSignInPage(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->assertRedirectedTo('/', $this->get('/login'));
    }
}
