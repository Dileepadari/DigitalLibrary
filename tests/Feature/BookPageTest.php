<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class BookPageTest extends DatabaseTestCase
{
    public function testTheBookPageShowsItsMetadata(): void
    {
        $this->makeBook('On the Origin of Species', [
            'authors'     => ['Charles Darwin'],
            'year'        => 1859,
            'categories'  => ['/academics/reference/'],
            'tags'        => ['Science'],
            'description' => 'Descent with modification.',
        ]);

        $body = $this->get('/books/on-the-origin-of-species')->body();

        $this->assertStringContainsString('On the Origin of Species', $body);
        $this->assertStringContainsString('Charles Darwin', $body);
        $this->assertStringContainsString('1859', $body);
        $this->assertStringContainsString('Descent with modification.', $body);
        $this->assertStringContainsString('Science', $body);
        $this->assertStringContainsString('Public domain', $body);
    }

    public function testAGuestCannotSeeAnUnpublishedRecord(): void
    {
        $this->makeBook('Waiting For Review', ['status' => 'pending']);

        $this->assertSame(404, $this->get('/books/waiting-for-review')->status());
    }

    public function testALibrarianCanSeeAnUnpublishedRecord(): void
    {
        $this->makeBook('Waiting For Review', ['status' => 'pending']);
        $librarian = $this->makeUser('libby', 'librarian');
        $this->signIn($librarian['email']);

        $response = $this->get('/books/waiting-for-review');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Awaiting review', $response->body());
    }

    public function testAnUnknownBookIs404(): void
    {
        $this->assertSame(404, $this->get('/books/no-such-book')->status());
    }

    public function testViewsAreCounted(): void
    {
        $id = $this->makeBook('Meditations');

        $this->get('/books/meditations');
        $this->get('/books/meditations');

        $this->assertSame(2, (int) $this->db->scalar('SELECT view_count FROM books WHERE id = ?', [$id]));
    }

    public function testRelatedBooksShareATagAndExcludeThisOne(): void
    {
        $this->makeBook('The Time Machine', ['tags' => ['Science fiction']]);
        $this->makeBook('Frankenstein', ['tags' => ['Science fiction']]);

        $body = $this->get('/books/the-time-machine')->body();

        $this->assertStringContainsString('Related', $body);
        $this->assertStringContainsString('Frankenstein', $body);

        // Exactly one card in the related grid, and it is not this book.
        $this->assertSame(1, substr_count($body, 'book-card__title'));
        $this->assertStringNotContainsString('/books/the-time-machine"', $body);
    }

    public function testTheCategoryIsLinkedFromTheBook(): void
    {
        $this->makeBook('Polity Notes', ['categories' => ['/academics/competitive-exams/upsc/']]);

        $this->assertStringContainsString(
            '/categories/academics/competitive-exams/upsc',
            $this->get('/books/polity-notes')->body()
        );
    }

    public function testTwoBooksWithTheSameTitleGetDifferentAddresses(): void
    {
        $this->makeBook('Selected Poems');
        $this->makeBook('Selected Poems');

        $this->assertSame(200, $this->get('/books/selected-poems')->status());
        $this->assertSame(200, $this->get('/books/selected-poems-2')->status());
    }
}
