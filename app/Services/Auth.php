<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Request;
use App\Core\Session;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Support\AuthResult;
use App\Support\Password;

/**
 * Who is signed in, and how they got there.
 *
 * The session holds the user id and nothing else, so a role change or a ban
 * takes effect on the signed-in user's very next request rather than when their
 * cookie happens to expire.
 */
final class Auth
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 900;

    private ?User $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly AuditLogRepository $audit,
        private readonly Config $config,
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $id = $this->session->get('user_id');

        return is_int($id) ? $id : null;
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        $user = $this->users->findById($id);

        // The account was deleted or banned since the cookie was issued.
        if ($user === null || !$user->canSignIn()) {
            $this->logout();
            $this->resolved = true;

            return null;
        }

        return $this->user = $user;
    }

    public function attempt(string $email, string $password, Request $request): AuthResult
    {
        $ipHash = $this->ipHash($request->ip());

        if ($this->attempts->recentFailures($email, $ipHash, self::WINDOW_SECONDS) >= self::MAX_FAILURES) {
            return AuthResult::Throttled;
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || !Password::verify($password, $user->passwordHash)) {
            $this->attempts->record($email, $ipHash, false);

            return AuthResult::InvalidCredentials;
        }

        if (!$user->canSignIn()) {
            $this->attempts->record($email, $ipHash, false);

            return AuthResult::Banned;
        }

        if (Password::needsRehash($user->passwordHash)) {
            $this->users->updatePassword($user->id, Password::hash($password));
        }

        $this->attempts->record($email, $ipHash, true);
        $this->attempts->clear($email, $ipHash);
        $this->users->expireStatus($user->id);
        $this->login($user);

        $this->audit->record(
            $user->id,
            'auth.sign_in',
            'user',
            $user->id,
            null,
            null,
            $ipHash,
            $request->userAgent()
        );

        return AuthResult::Success;
    }

    public function login(User $user): void
    {
        // New session id on every privilege change, so a fixated id is worthless.
        $this->session->regenerate();
        $this->session->put('user_id', $user->id);
        $this->user = $user;
        $this->resolved = true;
        $this->users->touchLastSeen($user->id);
    }

    public function logout(): void
    {
        $this->session->forget('user_id');
        $this->session->regenerate();
        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Stable per IP, useless as a location record. Keyed on APP_KEY so two
     * installs sharing a database cannot correlate.
     */
    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) $this->config->get('app.key', 'digital-library'));
    }
}
