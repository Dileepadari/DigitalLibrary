<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class CatalogueBrowseTest extends DatabaseTestCase
{
    public function testBrowseListsPublishedBooks(): void
    {
        $this->makeBook('Pride and Prejudice', ['authors' => ['Jane Austen']]);
        $this->makeBook('Frankenstein', ['authors' => ['Mary Shelley']]);

        $body = $this->get('/books')->body();

        $this->assertStringContainsString('Pride and Prejudice', $body);
        $this->assertStringContainsString('Jane Austen', $body);
        $this->assertStringContainsString('Frankenstein', $body);
    }

    public function testUnpublishedBooksAreNotListed(): void
    {
        $this->makeBook('Waiting For Review', ['status' => 'pending']);
        $this->makeBook('Hidden Away', ['status' => 'hidden']);
        $this->makeBook('Out In The Open');

        $body = $this->get('/books')->body();

        $this->assertStringContainsString('Out In The Open', $body);
        $this->assertStringNotContainsString('Waiting For Review', $body);
        $this->assertStringNotContainsString('Hidden Away', $body);
    }

    public function testSearchMatchesTheTitle(): void
    {
        $this->makeBook('The Time Machine');
        $this->makeBook('Pride and Prejudice');

        $body = $this->get('/search?q=machine')->body();

        $this->assertStringContainsString('The Time Machine', $body);
        $this->assertStringNotContainsString('Pride and Prejudice', $body);
    }

    public function testSearchMatchesTheAuthor(): void
    {
        $this->makeBook('On the Origin of Species', ['authors' => ['Charles Darwin']]);
        $this->makeBook('The Time Machine', ['authors' => ['H. G. Wells']]);

        $body = $this->get('/search?q=darwin')->body();

        $this->assertStringContainsString('On the Origin of Species', $body);
        $this->assertStringNotContainsString('The Time Machine', $body);
    }

    public function testSearchMatchesTheDescription(): void
    {
        $this->makeBook('An Untitled Work', ['description' => 'A treatise concerning barnacles and pigeons.']);
        $this->makeBook('Something Else');

        $body = $this->get('/search?q=barnacles')->body();

        $this->assertStringContainsString('An Untitled Work', $body);
        $this->assertStringNotContainsString('Something Else', $body);
    }

    public function testAShortSearchStillWorksThroughTheLikeClause(): void
    {
        // Two letters is under MySQL's fulltext minimum, so only the LIKE half
        // of the query can find this.
        $this->makeBook('Ox Farming Today');

        $this->assertStringContainsString('Ox Farming Today', $this->get('/search?q=Ox')->body());
    }

    public function testFilteringByCategoryIncludesTheWholeSubtree(): void
    {
        $this->makeBook('Polity Notes', ['categories' => ['/academics/competitive-exams/upsc/']]);
        $this->makeBook('A Novel', ['categories' => ['/stories/novels/']]);

        $body = $this->get('/books?category=academics')->body();

        $this->assertStringContainsString('Polity Notes', $body);
        $this->assertStringNotContainsString('A Novel', $body);
    }

    public function testFilteringByTag(): void
    {
        $this->makeBook('A Romance', ['tags' => ['Romance']]);
        $this->makeBook('A Textbook', ['tags' => ['Mathematics']]);

        $body = $this->get('/books?tag=romance')->body();

        $this->assertStringContainsString('A Romance', $body);
        $this->assertStringNotContainsString('A Textbook', $body);
    }

    public function testFilteringByKindAndLanguage(): void
    {
        $this->makeBook('A Comic Book', ['type' => 'comic']);
        $this->makeBook('Hindi Reader', ['language' => 'hi']);
        $this->makeBook('Ordinary Book');

        $comics = $this->get('/books?type=comic')->body();
        $hindi = $this->get('/books?language=hi')->body();

        $this->assertStringContainsString('A Comic Book', $comics);
        $this->assertStringNotContainsString('Ordinary Book', $comics);
        $this->assertStringContainsString('Hindi Reader', $hindi);
        $this->assertStringNotContainsString('Ordinary Book', $hindi);
    }

    public function testFacetsCountTheFilteredSet(): void
    {
        $this->makeBook('A Comic Book', ['type' => 'comic']);
        $this->makeBook('Ordinary Book');
        $this->makeBook('Another Ordinary Book');

        $container = $this->kernel()->container();
        $facets = $container->get(\App\Repositories\BookRepository::class)->facets([]);

        $this->assertSame(2, $facets['content_type']['book']);
        $this->assertSame(1, $facets['content_type']['comic']);
    }

    public function testResultsArePaginated(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->makeBook('Book Number ' . $i);
        }

        $first = $this->get('/books')->body();
        $second = $this->get('/books?page=2')->body();

        $this->assertSame(24, substr_count($first, 'book-card__title'));
        $this->assertSame(2, substr_count($second, 'book-card__title'));
        $this->assertStringContainsString('Page 2 of 2', $second);
    }

    public function testTheCategoryTreeIsBrowsable(): void
    {
        $this->makeBook('Polity Notes', ['categories' => ['/academics/competitive-exams/upsc/']]);

        $index = $this->get('/categories')->body();
        $this->assertStringContainsString('Competitive Exams', $index);

        $deep = $this->get('/categories/academics/competitive-exams/upsc');
        $this->assertSame(200, $deep->status());
        $this->assertStringContainsString('Polity Notes', $deep->body());

        // A parent lists what is below it, not just what is pinned to it.
        $this->assertStringContainsString('Polity Notes', $this->get('/categories/academics')->body());
    }

    public function testAnUnknownCategoryIs404(): void
    {
        $this->assertSame(404, $this->get('/categories/nothing/here')->status());
    }

    public function testTagPagesAndAliases(): void
    {
        $this->makeBook('A Space Story', ['tags' => ['Science fiction']]);

        $this->assertStringContainsString('A Space Story', $this->get('/tags/science-fiction')->body());

        // "sci-fi" is an alias, so it redirects to the tag it folds into.
        $this->assertRedirectedTo('/tags/science-fiction', $this->get('/tags/sci-fi'));
    }

    public function testAnUnknownTagIs404(): void
    {
        $this->assertSame(404, $this->get('/tags/not-a-tag')->status());
    }
}
