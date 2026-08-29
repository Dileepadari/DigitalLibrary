<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class RegistrationTest extends DatabaseTestCase
{
    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Asha Rao',
            'username'              => 'asha',
            'email'                 => 'asha@example.com',
            'password'              => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ], $overrides);
    }

    public function testTheFormRenders(): void
    {
        $response = $this->get('/register');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Create an account', $response->body());
    }

    public function testTheFirstAccountBecomesAVerifiedAdmin(): void
    {
        $response = $this->post('/register', $this->form());

        $this->assertRedirectedTo('/', $response);

        $row = $this->db->first('SELECT role, email_verified_at FROM users WHERE username = ?', ['asha']);

        $this->assertSame('admin', $row['role']);
        $this->assertNotNull($row['email_verified_at'], 'The first admin should not have to confirm an email.');
    }

    public function testTheSecondAccountIsAnUnverifiedMember(): void
    {
        $this->makeUser('founder', 'admin');

        $response = $this->post('/register', $this->form());

        $this->assertRedirectedTo('/verify-email', $response);

        $row = $this->db->first('SELECT role, email_verified_at FROM users WHERE username = ?', ['asha']);

        $this->assertSame('member', $row['role']);
        $this->assertNull($row['email_verified_at']);
    }

    public function testRegistrationSignsTheNewAccountIn(): void
    {
        $this->post('/register', $this->form());

        $this->assertSame(
            (int) $this->db->scalar('SELECT id FROM users WHERE username = ?', ['asha']),
            $_SESSION['user_id'] ?? null
        );
    }

    public function testADuplicateEmailIsRejected(): void
    {
        $this->makeUser('founder', 'admin');
        $this->post('/register', $this->form());
        $this->post('/logout');

        $response = $this->post('/register', $this->form(['username' => 'asha2']));

        $this->assertRedirectedTo('/register', $response);
        $count = (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE email = ?', ['asha@example.com']);

        $this->assertSame(1, $count);
        $this->assertArrayHasKey('email', $_SESSION['_flash']['errors']);
    }

    public function testADuplicateUsernameIsRejected(): void
    {
        $this->makeUser('founder', 'admin');
        $this->post('/register', $this->form());
        $this->post('/logout');

        $this->post('/register', $this->form(['email' => 'other@example.com']));

        $this->assertArrayHasKey('username', $_SESSION['_flash']['errors']);
        $this->assertSame(2, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testAShortPasswordIsRejected(): void
    {
        $response = $this->post('/register', $this->form([
            'password'              => 'short',
            'password_confirmation' => 'short',
        ]));

        $this->assertRedirectedTo('/register', $response);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testAMismatchedConfirmationIsRejected(): void
    {
        $this->post('/register', $this->form(['password_confirmation' => 'something-else-entirely']));

        $this->assertArrayHasKey('password_confirmation', $_SESSION['_flash']['errors']);
        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testAUsernameWithSpacesIsRejected(): void
    {
        $this->post('/register', $this->form(['username' => 'Asha Rao']));

        $this->assertArrayHasKey('username', $_SESSION['_flash']['errors']);
    }

    public function testTheRegistrationIsAudited(): void
    {
        $this->post('/register', $this->form());

        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs WHERE action = ?', ['account.registered'])
        );
    }
}
