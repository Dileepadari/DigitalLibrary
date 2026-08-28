<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

/**
 * Publishing a collection, and what a published one lets other people do.
 */
final class CollectionPublishTest extends DatabaseTestCase
{
    /**
     * A private collection with a folder and two books in it.
     *
     * @return array{id: int, path: string, prelims: int, owner: int}
     */
    private function builtCollection(string $email): array
    {
        $this->signIn($email);
        $this->post('/collections', ['name' => 'UPSC Preparation', 'visibility' => 'private']);
        $id = (int) $this->db->scalar('SELECT id FROM collections ORDER BY id DESC LIMIT 1');

        $this->post('/collections/' . $id, ['action' => 'child', 'name' => 'Prelims']);
        $prelims = (int) $this->db->scalar('SELECT id FROM collections ORDER BY id DESC LIMIT 1');

        $this->makeBook('Indian Polity');
        $this->makeBook('Ancient India');
        $this->post('/collections/' . $id, ['action' => 'add-book', 'slug' => 'indian-polity']);
        $this->post('/collections/' . $prelims, ['action' => 'add-book', 'slug' => 'ancient-india']);

        $owner = (int) $this->db->scalar('SELECT owner_id FROM collections WHERE id = ?', [$id]);

        return ['id' => $id, 'path' => 'upsc-preparation', 'prelims' => $prelims, 'owner' => $owner];
    }

    public function testAnEmptyCollectionCannotBePublished(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);
        $this->post('/collections', ['name' => 'Nothing In Here', 'visibility' => 'private']);
        $id = (int) $this->db->scalar('SELECT id FROM collections ORDER BY id DESC LIMIT 1');

        $this->post('/collections/' . $id, ['action' => 'publish']);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM moderation_requests'));
        $this->assertStringContainsString('Put something in it', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testPublishingSendsTheWholeTreeToTheQueue(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->builtCollection($user['email']);

        $this->post('/collections/' . $collection['id'], ['action' => 'publish']);

        $statuses = array_column(
            $this->db->select('SELECT review_status FROM collections ORDER BY id'),
            'review_status'
        );
        $this->assertSame(['pending', 'pending'], $statuses, 'Every node waits, not just the root.');

        $row = $this->db->first('SELECT subject_type, subject_id, title FROM moderation_requests');
        $this->assertSame('collection_publish', $row['subject_type']);
        $this->assertSame($collection['id'], (int) $row['subject_id']);
        $this->assertStringContainsString('UPSC Preparation', (string) $row['title']);

        // Still nobody else's business until it is approved.
        $this->post('/logout');
        $this->assertSame(404, $this->get('/collections/upsc-preparation')->status());
    }

    public function testItCannotBeSentTwice(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->builtCollection($user['email']);

        $this->post('/collections/' . $collection['id'], ['action' => 'publish']);
        $this->post('/collections/' . $collection['id'], ['action' => 'publish']);

        $this->assertSame(1, (int) $this->db->scalar('SELECT COUNT(*) FROM moderation_requests'));
    }

    public function testApprovalMakesTheWholeTreePublic(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->builtCollection($user['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'publish']);

        $queueId = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $queueId . '/claim');
        $this->post('/librarian/queue/' . $queueId . '/decide', ['decision' => 'approve']);

        $rows = $this->db->select('SELECT visibility, review_status FROM collections');

        foreach ($rows as $row) {
            $this->assertSame('public', $row['visibility']);
            $this->assertSame('approved', $row['review_status']);
        }

        $this->post('/logout');
        $this->assertSame(200, $this->get('/collections/upsc-preparation/prelims')->status());
        // Match the link, not the name: the "start a collection" form uses
        // "UPSC Preparation" as its placeholder text.
        $this->assertStringContainsString('/collections/upsc-preparation', $this->get('/collections')->body());
    }

    public function testTheOwnerIsToldEitherWay(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->builtCollection($user['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'publish']);

        $queueId = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $queueId . '/claim');
        $this->post('/librarian/queue/' . $queueId . '/decide', ['decision' => 'approve']);

        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'moderation.approved'",
                [$collection['owner']]
            )
        );
    }

    public function testRejectionLeavesItPrivate(): void
    {
        $user = $this->makeUser('asha');
        $collection = $this->builtCollection($user['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'publish']);

        $queueId = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $queueId . '/claim');
        $this->post('/librarian/queue/' . $queueId . '/decide', [
            'decision'      => 'reject',
            'canned_reason' => 'Off topic for this library',
        ]);

        $row = $this->db->first('SELECT visibility, review_status FROM collections WHERE id = ?', [$collection['id']]);

        $this->assertSame('private', $row['visibility']);
        $this->assertSame('rejected', $row['review_status']);
        $this->assertStringNotContainsString('/collections/upsc-preparation', $this->get('/collections')->body());
    }

    /** @return array{id: int, owner: int} the published collection */
    private function publishedCollection(): array
    {
        $owner = $this->makeUser('asha');
        $collection = $this->builtCollection($owner['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'publish']);

        $queueId = (int) $this->db->scalar('SELECT id FROM moderation_requests ORDER BY id DESC LIMIT 1');
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/queue/' . $queueId . '/claim');
        $this->post('/librarian/queue/' . $queueId . '/decide', ['decision' => 'approve']);

        return ['id' => $collection['id'], 'owner' => $collection['owner']];
    }

    public function testForkingCopiesTheFoldersAndTheBooks(): void
    {
        $published = $this->publishedCollection();

        $forker = $this->makeUser('rahul');
        $this->signIn($forker['email']);
        $this->post('/collections/' . $published['id'], ['action' => 'fork']);

        $copy = $this->db->first(
            'SELECT id, name, visibility, forked_from_id FROM collections WHERE owner_id = ? AND parent_id IS NULL',
            [$forker['id']]
        );

        $this->assertNotNull($copy);
        $this->assertSame('UPSC Preparation', $copy['name']);
        $this->assertSame('private', $copy['visibility'], 'A fork is your own private copy.');
        $this->assertSame($published['id'], (int) $copy['forked_from_id']);

        // Two nodes and two books, the same shape as the original.
        $this->assertSame(
            2,
            (int) $this->db->scalar('SELECT COUNT(*) FROM collections WHERE root_id = ?', [$copy['id']])
        );
        $this->assertSame(
            2,
            (int) $this->db->scalar(
                'SELECT COUNT(*) FROM collection_items i
                 INNER JOIN collections c ON c.id = i.collection_id WHERE c.root_id = ?',
                [$copy['id']]
            )
        );
    }

    public function testAPrivateCollectionCannotBeForked(): void
    {
        $owner = $this->makeUser('asha');
        $collection = $this->builtCollection($owner['email']);

        $forker = $this->makeUser('rahul');
        $this->signIn($forker['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'fork']);

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT COUNT(*) FROM collections WHERE owner_id = ?', [$forker['id']])
        );
    }

    public function testFollowersHearWhenABookIsAdded(): void
    {
        $published = $this->publishedCollection();

        $follower = $this->makeUser('rahul');
        $this->signIn($follower['email']);
        $this->post('/collections/' . $published['id'], ['action' => 'follow']);

        $this->assertSame(
            1,
            (int) $this->db->scalar('SELECT follower_count FROM collections WHERE id = ?', [$published['id']])
        );

        $this->makeBook('Modern India');
        $this->signIn('asha@example.com');
        $this->post('/collections/' . $published['id'], ['action' => 'add-book', 'slug' => 'modern-india']);

        $this->assertSame(
            1,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'collection.item_added'",
                [$follower['id']]
            )
        );

        // The person who added it does not need telling.
        $this->assertSame(
            0,
            (int) $this->db->scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'collection.item_added'",
                [$published['owner']]
            )
        );
    }

    public function testFollowingIsAToggle(): void
    {
        $published = $this->publishedCollection();

        $follower = $this->makeUser('rahul');
        $this->signIn($follower['email']);
        $this->post('/collections/' . $published['id'], ['action' => 'follow']);
        $this->post('/collections/' . $published['id'], ['action' => 'follow']);

        $this->assertSame(
            0,
            (int) $this->db->scalar('SELECT follower_count FROM collections WHERE id = ?', [$published['id']])
        );
    }

    public function testAPrivateCollectionCannotBeFollowed(): void
    {
        $owner = $this->makeUser('asha');
        $collection = $this->builtCollection($owner['email']);

        $other = $this->makeUser('rahul');
        $this->signIn($other['email']);
        $this->post('/collections/' . $collection['id'], ['action' => 'follow']);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM collection_followers'));
    }
}
