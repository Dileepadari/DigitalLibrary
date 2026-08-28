<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Role;
use App\Support\UserStatus;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function testRolesAreOrdered(): void
    {
        $this->assertTrue(Role::Admin->outranks(Role::Librarian));
        $this->assertTrue(Role::Librarian->outranks(Role::Member));
        $this->assertFalse(Role::Member->outranks(Role::Member));
    }

    public function testValuesMatchTheDatabaseEnum(): void
    {
        $this->assertSame(['member', 'librarian', 'admin'], array_column(Role::all(), 'value'));
    }

    public function testEveryRoleHasALabelAndDescription(): void
    {
        foreach (Role::all() as $role) {
            $this->assertNotSame('', $role->label());
            $this->assertNotSame('', $role->description());
        }
    }

    public function testBannedCannotSignInAndMutedCannotContribute(): void
    {
        $this->assertTrue(UserStatus::Active->canSignIn());
        $this->assertTrue(UserStatus::Active->canContribute());

        $this->assertTrue(UserStatus::Muted->canSignIn());
        $this->assertFalse(UserStatus::Muted->canContribute());

        $this->assertFalse(UserStatus::Banned->canSignIn());
        $this->assertFalse(UserStatus::Banned->canContribute());
    }
}
