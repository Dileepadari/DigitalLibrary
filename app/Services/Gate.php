<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Models\User;
use App\Repositories\PermissionRepository;

/**
 * Permission checks. The keys and the role mapping live in the database
 * (migration 0003); this only resolves them for the current user and caches the
 * answer for the request.
 */
final class Gate
{
    /**
     * What someone with no account may do. Everything else needs a session, so
     * this list is the entire public surface of the site.
     *
     * @var list<string>
     */
    private const GUEST_PERMISSIONS = ['catalog.browse'];

    /** @var array<int, list<string>> */
    private array $cache = [];

    public function __construct(
        private readonly Auth $auth,
        private readonly PermissionRepository $permissions,
    ) {
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($this->auth->user()), true);
    }

    public function denies(string $permission): bool
    {
        return !$this->allows($permission);
    }

    /** @throws HttpException 401 when signed out, 403 when signed in without the permission */
    public function authorize(string $permission): void
    {
        if ($this->allows($permission)) {
            return;
        }

        throw $this->auth->check() ? HttpException::forbidden() : HttpException::unauthorized();
    }

    /**
     * A muted or unverified account keeps read permissions but loses the ones
     * that write to the library.
     */
    public function canContribute(): bool
    {
        $user = $this->auth->user();

        return $user !== null && $user->canContribute();
    }

    /** @return list<string> */
    public function permissionsFor(?User $user): array
    {
        if ($user === null) {
            return self::GUEST_PERMISSIONS;
        }

        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        return $this->cache[$user->id] = $this->permissions->keysForUser($user->id, $user->role);
    }
}
