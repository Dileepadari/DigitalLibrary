<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Support\Role;

final class PermissionRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The permission keys a role carries, before any per-user override.
     *
     * @return list<string>
     */
    public function keysForRole(Role $role): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['key'],
            $this->db->select(
                'SELECT p.key FROM permissions p
                 INNER JOIN role_permissions rp ON rp.permission_id = p.id
                 WHERE rp.role = ? ORDER BY p.key',
                [$role->value]
            )
        );
    }

    /**
     * The role's keys with the user's own grants added and revokes removed.
     *
     * @return list<string>
     */
    public function keysForUser(int $userId, Role $role): array
    {
        $keys = array_flip($this->keysForRole($role));

        foreach ($this->overridesFor($userId) as $key => $effect) {
            if ($effect === 'grant') {
                $keys[$key] = true;
            } else {
                unset($keys[$key]);
            }
        }

        $result = array_keys($keys);
        sort($result);

        /** @var list<string> $result */
        return $result;
    }

    /** @return array<string, string> permission key => grant|revoke */
    public function overridesFor(int $userId): array
    {
        $overrides = [];

        foreach (
            $this->db->select(
                'SELECT p.key, up.effect FROM user_permissions up
             INNER JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = ?',
                [$userId]
            ) as $row
        ) {
            $overrides[(string) $row['key']] = (string) $row['effect'];
        }

        return $overrides;
    }

    /** @return list<array{key: string, description: string, area: string}> */
    public function all(): array
    {
        /** @var list<array{key: string, description: string, area: string}> $rows */
        $rows = $this->db->select('SELECT `key`, description, area FROM permissions ORDER BY area, `key`');

        return $rows;
    }

    public function idFor(string $key): ?int
    {
        $id = $this->db->scalar('SELECT id FROM permissions WHERE `key` = ?', [$key]);

        return $id === null ? null : (int) $id;
    }

    public function setOverride(int $userId, string $key, string $effect, ?int $grantedBy = null): bool
    {
        $permissionId = $this->idFor($key);

        if ($permissionId === null) {
            return false;
        }

        $this->db->execute(
            'INSERT INTO user_permissions (user_id, permission_id, effect, granted_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE effect = VALUES(effect), granted_by = VALUES(granted_by)',
            [$userId, $permissionId, $effect, $grantedBy]
        );

        return true;
    }

    public function clearOverride(int $userId, string $key): void
    {
        $permissionId = $this->idFor($key);

        if ($permissionId !== null) {
            $this->db->execute(
                'DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?',
                [$userId, $permissionId]
            );
        }
    }
}
