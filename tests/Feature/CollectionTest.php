<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CollectionRepository;
use Tests\DatabaseTestCase;

final class CollectionTest extends DatabaseTestCase
{
    /** @return array{id: int, path: string} */
    private function startCollection(string $email, string $name = 'UPSC Preparation'): array
    {
        $this->signIn($email);
        $this->post('/collections', ['name' => $name, 'visibility' => 'private']);

        $row = $this->db->first('SELECT id, path FROM collections WHERE name = ? ORDER BY id DESC', [$name]);

        return ['id' => (int) $row['id'], 'path' => trim((string) $row['path'], '/')];
    }

    /** Adds a folder inside a node and returns its id. */
    private function addFolder(int $parentId, string $name): int
    {
        $this->post('/collections/' . $parentId, ['action' => 'child', 'name' => $name]);

        return (int) $this->db->scalar('SELECT id FROM collections ORDER BY id DESC LIMIT 1');
    }

    public function testAMemberStartsACollection(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->startCollection($user['email']);

        $row = $this->db->first('SELECT * FROM collections WHERE id = ?', [$collection['id']]);

        $this->assertSame('upsc-preparation', $row['slug']);
        $this->assertSame('/upsc-preparation/', $row['path']);
        $this->assertSame(0, (int) $row['depth']);
        $this->assertSame('private', $row['visibility']);
        $this->assertSame($user['id'], (int) $row['owner_id']);
        $this->assertSame($collection['id'], (int) $row['root_id'], 'A root is its own root.');
    }

    public function testFoldersNestToAnyDepthAndCarryTheirPath(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->startCollection($user['email']);

        $prelims = $this->addFolder($collection['id'], 'Prelims');
        $history = $this->addFolder($prelims, 'History');
        $ancient = $this->addFolder($history, 'Ancient India');

        $row = $this->db->first('SELECT path, depth, root_id FROM collections WHERE id = ?', [$ancient]);

        $this->assertSame('/upsc-preparation/prelims/history/ancient-india/', $row['path']);
        $this->assertSame(3, (int) $row['depth']);
        $this->assertSame($collection['id'], (int) $row['root_id']);

        $response = $this->get('/collections/upsc-preparation/prelims/history/ancient-india');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Ancient India', $response->body());
    }

    public function testTheTreeIsCappedInDepth(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->startCollection($user['email']);
        $parent = $collection['id'];

        for ($depth = 1; $depth < CollectionRepository::MAX_DEPTH; $depth++) {
            $parent = $this->addFolder($parent, 'Level ' . $depth);
        }

        $before = (int) $this->db->scalar('SELECT COUNT(*) FROM collections');
        $this->post('/collections/' . $parent, ['action' => 'child', 'name' => 'One Too Deep']);

        $this->assertSame($before, (int) $this->db->scalar('SELECT COUNT(*) FROM collections'));
        $this->assertStringContainsString('deep', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testTwoPeopleCanBothHaveUpscPreparation(): void
    {
        $first = $this->makeUser('asha');
        $second = $this->makeUser('rahul');

        $this->startCollection($first['email']);
        $this->startCollection($second['email']);

        $paths = array_column($this->db->select('SELECT path FROM collections ORDER BY id'), 'path');

        $this->assertSame(['/upsc-preparation/', '/upsc-preparation-2/'], $paths);
    }

    public function testAPrivateCollectionIsNotThereForAnyoneElse(): void
    {
        $owner = $this->makeUser('asha');
        $collection = $this->startCollection($owner['email']);

        $this->assertSame(200, $this->get('/collections/' . $collection['path'])->status());

        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);
        $this->assertSame(404, $this->get('/collections/' . $collection['path'])->status());

        $this->post('/logout');
        $this->assertSame(404, $this->get('/collections/' . $collection['path'])->status());
    }

    public function testAnUnlistedCollectionWorksForAnyoneWithTheAddress(): void
    {
        $owner = $this->makeUser('asha');
        $collection = $this->startCollection($owner['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'visibility', 'visibility' => 'unlisted']);

        $this->post('/logout');

        $this->assertSame(200, $this->get('/collections/' . $collection['path'])->status());
        $this->assertStringNotContainsString(
            '/collections/upsc-preparation',
            $this->get('/collections')->body(),
            'Unlisted means not listed.'
        );
    }

    public function testBooksArePinnedToAFolderAndCounted(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->startCollection($user['email']);
        $history = $this->addFolder($collection['id'], 'History');

        $this->makeBook('Ancient India', ['authors' => ['R. C. Majumdar']]);
        $this->post('/collections/' . $history, [
            'action' => 'add-book',
            'slug'   => 'ancient-india',
            'note'   => 'Chapters 3 to 7 only.',
        ]);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM collection_items'));

        // The count on a node includes everything below it, like categories.
        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT item_count FROM collections WHERE id = ?', [$collection['id']])
        );

        $body = $this->get('/collections/' . $collection['path'] . '/history')->body();
        $this->assertStringContainsString('Ancient India', $body);
        $this->assertStringContainsString('Chapters 3 to 7 only.', $body);
        $this->assertStringContainsString('R. C. Majumdar', $body, 'The byline should be the real author.');
    }

    public function testTheSameBookCannotBePinnedTwice(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->startCollection($user['email']);
        $this->makeBook('Ancient India');

        $this->post('/collections/' . $collection['id'], ['action' => 'add-book', 'slug' => 'ancient-india']);
        $this->post('/collections/' . $collection['id'], ['action' => 'add-book', 'slug' => 'ancient-india']);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM collection_items'));
        $this->assertStringContainsString('already in', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testBooksCanBeReorderedAndRemoved(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->startCollection($user['email']);

        $first = $this->makeBook('First Book');
        $second = $this->makeBook('Second Book');

        $this->post('/collections/' . $collection['id'], ['action' => 'add-book', 'slug' => 'first-book']);
        $this->post('/collections/' . $collection['id'], ['action' => 'add-book', 'slug' => 'second-book']);

        $this->post('/collections/' . $collection['id'], [
            'action'  => 'move-book-up',
            'book_id' => (string) $second,
        ]);

        $order = array_column(
            $this->db->select('SELECT book_id FROM collection_items ORDER BY position'),
            'book_id'
        );
        $this->assertSame([$second, $first], array_map('intval', $order));

        $this->post('/collections/' . $collection['id'], [
            'action'  => 'remove-book',
            'book_id' => (string) $second,
        ]);
        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM collection_items'));
    }

    public function testSomeoneElseCannotCurateYourCollection(): void
    {
        $owner = $this->makeUser('asha');
        $collection = $this->startCollection($owner['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'visibility', 'visibility' => 'unlisted']);
        $this->makeBook('Ancient India');

        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'add-book', 'slug' => 'ancient-india']);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM collection_items'));
        $this->assertStringContainsString('not your collection', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testAnInvitedMaintainerCanCurate(): void
    {
        $owner = $this->makeUser('asha');
        $collection = $this->startCollection($owner['email']);
        $helper = $this->makeUser('rahul');
        $this->makeBook('Ancient India');

        $this->post('/collections/' . $collection['id'], [
            'action'   => 'add-maintainer',
            'username' => 'rahul',
        ]);

        $this->signIn($helper['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'add-book', 'slug' => 'ancient-india']);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM collection_items'));
        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'collection.invited'",
                [$helper['id']]
            )
        );
    }

    public function testDeletingAFolderTakesItsSubtree(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->startCollection($user['email']);
        $prelims = $this->addFolder($collection['id'], 'Prelims');
        $this->addFolder($prelims, 'History');

        $this->assertSame(3, (int) $this->db->scalar('SELECT COUNT(*) FROM collections'));

        $this->post('/collections/' . $prelims, ['action' => 'delete']);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM collections'));
    }

    public function testRenamingKeepsTheAddress(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->startCollection($user['email']);

        $this->post('/collections/' . $collection['id'], [
            'action' => 'rename',
            'name'   => 'Civil Services Preparation',
        ]);

        $row = $this->db->first('SELECT name, path FROM collections WHERE id = ?', [$collection['id']]);

        $this->assertSame('Civil Services Preparation', $row['name']);
        $this->assertSame('/upsc-preparation/', $row['path'], 'Links that already exist must keep working.');
    }

    public function testAnUnknownAddressIs404(): void
    {
        $this->assertSame(404, $this->get('/collections/nothing/here')->status());
    }
}
