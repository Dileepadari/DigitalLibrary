<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Mailer;
use App\Core\Router;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\AuthTokenRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Support\Password;
use App\Support\Role;

/**
 * Registration, email verification and password resets.
 */
final class AccountService
{
    private const VERIFICATION_TTL = 172800;
    private const RESET_TTL = 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly AuditLogRepository $audit,
        private readonly Mailer $mailer,
        private readonly Router $router,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * The first account on a fresh install becomes the admin, already verified.
     * Someone has to be able to run the place, and an install with no admin
     * cannot promote anyone.
     */
    public function register(string $name, string $username, string $email, string $password): User
    {
        $isFirst = $this->users->count() === 0;

        $id = $this->users->create(
            $name,
            $username,
            $email,
            Password::hash($password),
            $isFirst ? Role::Admin : Role::Member,
            $isFirst,
            (int) $this->settings->get('uploads.default_quota', 2147483648),
        );

        $user = $this->users->findById($id);

        if ($user === null) {
            throw new \RuntimeException('The account was created but could not be read back.');
        }

        $this->audit->record($id, 'account.registered', 'user', $id, null, [
            'role'  => $user->role->value,
            'first' => $isFirst,
        ]);

        if (!$isFirst) {
            $this->sendVerificationLink($user);
        }

        return $user;
    }

    public function sendVerificationLink(User $user): void
    {
        if ($user->isVerified()) {
            return;
        }

        $token = $this->tokens->issue($user->id, AuthTokenRepository::EMAIL_VERIFICATION, self::VERIFICATION_TTL);
        $link = $this->absoluteUrl('verify.consume', ['token' => $token]);

        $this->mailer->send(
            $user->email,
            'Confirm your email address',
            "Hello {$user->name},\n\n"
            . "Confirm your email address to finish setting up your account:\n\n"
            . "{$link}\n\n"
            . "The link works for two days. If you did not create an account, ignore this message.\n"
        );
    }

    public function verify(string $token): ?User
    {
        $record = $this->tokens->findValid($token, AuthTokenRepository::EMAIL_VERIFICATION);

        if ($record === null) {
            return null;
        }

        $this->tokens->markUsed($record['id']);
        $this->users->markVerified($record['user_id']);
        $this->audit->record($record['user_id'], 'account.email_verified', 'user', $record['user_id']);

        return $this->users->findById($record['user_id']);
    }

    /**
     * Always looks like it worked. Telling a stranger whether an address has an
     * account here is a disclosure, and the flow does not need it.
     */
    public function sendPasswordResetLink(string $email): void
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !$user->canSignIn()) {
            return;
        }

        $token = $this->tokens->issue($user->id, AuthTokenRepository::PASSWORD_RESET, self::RESET_TTL);
        $link = $this->absoluteUrl('password.reset', ['token' => $token]);

        $this->mailer->send(
            $user->email,
            'Reset your password',
            "Hello {$user->name},\n\n"
            . "Use this link to choose a new password:\n\n"
            . "{$link}\n\n"
            . "The link works for one hour and once only. If you did not ask for it, nothing has changed.\n"
        );
    }

    public function resetPassword(string $token, string $password): bool
    {
        $record = $this->tokens->findValid($token, AuthTokenRepository::PASSWORD_RESET);

        if ($record === null) {
            return false;
        }

        $this->tokens->markUsed($record['id']);
        $this->users->updatePassword($record['user_id'], Password::hash($password));

        // A reset is also a recovery from a compromise: drop every other token.
        $this->tokens->deleteFor($record['user_id'], AuthTokenRepository::PASSWORD_RESET);
        $this->audit->record($record['user_id'], 'account.password_reset', 'user', $record['user_id']);

        return true;
    }

    public function changePassword(User $user, string $password): void
    {
        $this->users->updatePassword($user->id, Password::hash($password));
        $this->audit->record($user->id, 'account.password_changed', 'user', $user->id);
    }

    /** @param array<string, string> $parameters */
    private function absoluteUrl(string $route, array $parameters): string
    {
        return rtrim((string) $this->config->get('app.url'), '/') . $this->router->url($route, $parameters);
    }
}
