<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\CategoryRepository;
use Tests\DatabaseTestCase;

final class TaxonomyTest extends DatabaseTestCase
{
    public function testAGuestCannotReachTheTaxonomyScreen(): void
    {
        $this->assertRedirectedTo('/login', $this->get('/librarian/taxonomy'));
    }

    public function testAMemberCannotReachTheTaxonomyScreen(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $this->assertSame(403, $this->get('/librarian/taxonomy')->status());
    }

    public function testALibrarianSeesTheTreeAndTheQueue(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $body = $this->get('/librarian/taxonomy')->body();

        $this->assertStringContainsString('Competitive Exams', $body);
        $this->assertStringContainsString('Waiting for a decision', $body);
    }

    public function testALibrariansCategoryIsLiveImmediately(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $response = $this->post('/librarian/categories', ['name' => 'Cookery']);

        $this->assertRedirectedTo('/librarian/taxonomy', $response);

        $row = $this->db->first('SELECT status, path, depth FROM categories WHERE slug = ?', ['cookery']);

        $this->assertSame('active', $row['status']);
        $this->assertSame('/cookery/', $row['path']);
        $this->assertSame(0, (int) $row['depth']);
        $this->assertStringContainsString('Cookery', $this->get('/categories')->body());
    }

    public function testAChildCategoryInheritsItsParentsPath(): void
    {
        $user = $this->makeUser('libby', 'librarian');
        $this->signIn($user['email']);

        $parent = $this->db->scalar('SELECT id FROM categories WHERE path = ?', ['/academics/competitive-exams/']);
        $this->post('/librarian/categories', ['name' => 'SSC', 'parent_id' => (string) $parent]);

        $row = $this->db->first('SELECT path, depth FROM categories WHERE slug = ?', ['ssc']);

        $this->assertSame('/academics/competitive-exams/ssc/', $row['path']);
        $this->assertSame(2, (int) $row['depth']);
        $this->assertSame(200, $this->get('/categories/academics/competitive-exams/ssc')->status());
    }

    public function testAMembersCategoryIsProposedAndStaysHidden(): void
    {
        $user = $this->makeUser('asha');
        $this->signIn($user['email']);

        $response = $this->post('/categories/propose', ['name' => 'Gardening']);

        $this->assertRedirectedTo('/categories', $response);
        $this->assertSame('pending', $this->db->scalar('SELECT status FROM categories WHERE slug = ?', ['gardening']));
        $this->assertStringNotContainsString('Gardening', $this->get('/categories')->body());
        $this->assertSame(404, $this->get('/categories/gardening')->status());
    }

    public function testALibrarianApprovesAProposedCategory(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/categories/propose', ['name' => 'Gardening']);

        $id = (int) $this->db->scalar('SELECT id FROM categories WHERE slug = ?', ['gardening']);
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->post('/librarian/categories/' . $id . '/decide', ['decision' => 'approve']);

        $this->assertSame('active', $this->db->scalar('SELECT status FROM categories WHERE id = ?', [$id]));
        $this->assertStringContainsString('Gardening', $this->get('/categories')->body());
    }

    public function testRejectingAProposedCategoryRemovesIt(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/categories/propose', ['name' => 'Gardening']);

        $id = (int) $this->db->scalar('SELECT id FROM categories WHERE slug = ?', ['gardening']);
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $this->post('/librarian/categories/' . $id . '/decide', ['decision' => 'reject']);

        $this->assertNull($this->db->scalar('SELECT id FROM categories WHERE id = ?', [$id]));
    }

    public function testAnAlreadyDecidedCategoryCannotBeDecidedAgain(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $id = (int) $this->db->scalar('SELECT id FROM categories WHERE path = ?', ['/stories/']);

        $this->post('/librarian/categories/' . $id . '/decide', ['decision' => 'reject']);

        $this->assertNotNull($this->db->scalar('SELECT id FROM categories WHERE id = ?', [$id]));
        $this->assertStringContainsString('not waiting', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testTheTreeHasADepthCap(): void
    {
        $repository = $this->kernel()->container()->get(CategoryRepository::class);
        $parentId = null;

        for ($depth = 0; $depth < CategoryRepository::MAX_DEPTH; $depth++) {
            $parentId = $repository->create('Level ' . $depth, $parentId, null, 'active', null);
        }

        $this->expectException(\RuntimeException::class);
        $repository->create('One Too Deep', $parentId, null, 'active', null);
    }

    public function testALibrarianApprovesAProposedTag(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/books', [
            'title'   => 'A Book With A New Tag',
            'licence' => 'public_domain',
            'tags'    => 'Zeppelins',
        ]);

        $id = (int) $this->db->scalar('SELECT id FROM tags WHERE slug = ?', ['zeppelins']);
        $this->assertSame('pending', $this->db->scalar('SELECT status FROM tags WHERE id = ?', [$id]));

        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/tags/' . $id . '/decide', ['decision' => 'approve']);

        $this->assertSame('active', $this->db->scalar('SELECT status FROM tags WHERE id = ?', [$id]));
        $this->assertStringContainsString('Zeppelins', $this->get('/tags')->body());
    }

    public function testAnAliasFoldsOneSpellingIntoAnother(): void
    {
        $this->makeBook('A Space Story', ['tags' => ['Science fiction']]);
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $id = (int) $this->db->scalar('SELECT id FROM tags WHERE slug = ?', ['science-fiction']);
        $this->post('/librarian/tags/' . $id . '/alias', ['alias' => 'Space Opera']);

        $this->assertRedirectedTo('/tags/science-fiction', $this->get('/tags/space-opera'));
    }

    public function testAnAliasCannotShadowAnExistingTag(): void
    {
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $id = (int) $this->db->scalar('SELECT id FROM tags WHERE slug = ?', ['science-fiction']);
        $this->post('/librarian/tags/' . $id . '/alias', ['alias' => 'Romance']);

        $this->assertSame(0, (int) $this->db->scalar('SELECT COUNT(*) FROM tag_aliases WHERE alias = ?', ['romance']));
        $this->assertStringContainsString('already a tag', (string) ($_SESSION['_flash']['error'] ?? ''));
    }

    public function testTaxonomyDecisionsAreAudited(): void
    {
        $member = $this->makeUser('asha');
        $this->signIn($member['email']);
        $this->post('/categories/propose', ['name' => 'Gardening']);

        $id = (int) $this->db->scalar('SELECT id FROM categories WHERE slug = ?', ['gardening']);
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);
        $this->post('/librarian/categories/' . $id . '/decide', ['decision' => 'approve']);

        // The proposal is a queue item, so the decision is recorded by the
        // moderation engine rather than by the taxonomy service.
        $actions = array_column(
            $this->db->select(
                "SELECT action FROM audit_logs WHERE action LIKE 'category.%' OR action LIKE 'moderation.%'
                 ORDER BY id"
            ),
            'action'
        );

        $this->assertSame(['category.proposed', 'moderation.opened', 'moderation.approved'], $actions);
    }

    public function testCategoryCountsFollowTheSubtree(): void
    {
        $this->makeBook('Polity Notes', ['categories' => ['/academics/competitive-exams/upsc/']]);

        $counts = [];

        foreach ($this->db->select('SELECT path, book_count FROM categories') as $row) {
            $counts[(string) $row['path']] = (int) $row['book_count'];
        }

        $this->assertSame(1, $counts['/academics/']);
        $this->assertSame(1, $counts['/academics/competitive-exams/']);
        $this->assertSame(1, $counts['/academics/competitive-exams/upsc/']);
        $this->assertSame(0, $counts['/stories/']);
    }
}
