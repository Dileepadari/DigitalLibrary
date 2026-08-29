<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;
use App\Services\Gate;
use App\Support\Role;
use Tests\DatabaseTestCase;

final class PermissionTest extends DatabaseTestCase
{
    private function gate(): Gate
    {
        return $this->kernel()->container()->get(Gate::class);
    }

    private function permissions(): PermissionRepository
    {
        return $this->kernel()->container()->get(PermissionRepository::class);
    }

    public function testAGuestMayOnlyBrowse(): void
    {
        $gate = $this->gate();

        $this->assertTrue($gate->allows('catalog.browse'));
        $this->assertTrue($gate->denies('book.read'));
        $this->assertTrue($gate->denies('book.upload'));
    }

    public function testAMemberMayContributeButNotModerate(): void
    {
        $keys = $this->permissions()->keysForRole(Role::Member);

        $this->assertContains('book.upload', $keys);
        $this->assertContains('request.create', $keys);
        $this->assertContains('collection.propose.public', $keys);
        $this->assertNotContains('book.publish', $keys);
        $this->assertNotContains('moderation.queue', $keys);
        $this->assertNotContains('user.manage', $keys);
    }

    public function testALibrarianMayModerateButNotAdminister(): void
    {
        $keys = $this->permissions()->keysForRole(Role::Librarian);

        $this->assertContains('moderation.queue', $keys);
        $this->assertContains('book.publish', $keys);
        $this->assertContains('collection.approve', $keys);
        $this->assertNotContains('user.manage', $keys);
        $this->assertNotContains('librarian.approve', $keys);
        $this->assertNotContains('settings.manage', $keys);
    }

    public function testAnAdminHasEveryPermission(): void
    {
        $all = array_column($this->permissions()->all(), 'key');
        $keys = $this->permissions()->keysForRole(Role::Admin);

        sort($all);

        $this->assertSame($all, $keys);
    }

    public function testAGrantAddsOnePermissionWithoutChangingTheRole(): void
    {
        $user = $this->makeUser('asha');
        $this->permissions()->setOverride($user['id'], 'moderation.queue', 'grant');

        $keys = $this->permissions()->keysForUser($user['id'], Role::Member);

        $this->assertContains('moderation.queue', $keys);
        $this->assertSame('member', $this->db->scalar('SELECT role FROM users WHERE id = ?', [$user['id']]));
    }

    public function testARevokeTakesOnePermissionAway(): void
    {
        $user = $this->makeUser('asha');
        $this->permissions()->setOverride($user['id'], 'book.upload', 'revoke');

        $this->assertNotContains('book.upload', $this->permissions()->keysForUser($user['id'], Role::Member));
    }

    public function testClearingAnOverrideRestoresTheRoleDefault(): void
    {
        $user = $this->makeUser('asha');
        $this->permissions()->setOverride($user['id'], 'book.upload', 'revoke');
        $this->permissions()->clearOverride($user['id'], 'book.upload');

        $this->assertContains('book.upload', $this->permissions()->keysForUser($user['id'], Role::Member));
    }

    public function testAnUnknownPermissionCannotBeGranted(): void
    {
        $user = $this->makeUser('asha');

        $this->assertFalse($this->permissions()->setOverride($user['id'], 'not.a.permission', 'grant'));
    }

    public function testTheGateFollowsTheSignedInUser(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $gate = $this->gate();

        $this->assertTrue($gate->allows('moderation.queue'));
        $this->assertTrue($gate->denies('user.manage'));
    }

    public function testAnUnverifiedAccountCannotContribute(): void
    {
        $user = $this->makeUser('asha', 'member', false);
        $this->signIn($user['email']);

        $container = $this->kernel()->container();
        $unverified = $container->get(UserRepository::class)->findById($user['id']);

        $this->assertNotNull($unverified);
        $this->assertFalse($unverified->canContribute());
        $this->assertFalse($container->get(Gate::class)->canContribute());
    }

    public function testAMutedAccountCannotContributeButKeepsReading(): void
    {
        $user = $this->makeUser('asha', 'member', true, 'muted');
        $this->signIn($user['email']);

        $container = $this->kernel()->container();

        $this->assertFalse($container->get(Gate::class)->canContribute());
        $this->assertTrue($container->get(Gate::class)->allows('book.read'));
    }
}
