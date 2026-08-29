<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class PublicApiTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $response = $this->get($path);

        $this->assertSame(200, $response->status(), $path . ' should answer.');
        $this->assertStringContainsString('application/json', $response->headers()['Content-Type']);

        $decoded = json_decode($response->body(), true);
        $this->assertIsArray($decoded, $path . ' should be JSON.');

        return $decoded;
    }

    public function testTheBookListIsPublic(): void
    {
        $this->makeBook('Meditations', ['authors' => ['Marcus Aurelius'], 'year' => 180]);

        $payload = $this->json('/api/v1/books');

        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame('Meditations', $payload['data'][0]['title']);
        $this->assertSame(['Marcus Aurelius'], $payload['data'][0]['authors']);
        $this->assertSame('/books/meditations', $payload['data'][0]['url']);
    }

    public function testUnpublishedBooksStayOut(): void
    {
        $this->makeBook('Waiting', ['status' => 'pending']);
        $this->makeBook('Published');

        $payload = $this->json('/api/v1/books');

        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame('Published', $payload['data'][0]['title']);
    }

    public function testItSearchesAndFilters(): void
    {
        $this->makeBook('The Time Machine', ['tags' => ['Science fiction']]);
        $this->makeBook('Meditations', ['tags' => ['Philosophy']]);

        $this->assertSame(1, $this->json('/api/v1/books?q=machine')['meta']['total']);
        $this->assertSame(1, $this->json('/api/v1/books?tag=philosophy')['meta']['total']);
        $this->assertSame(2, $this->json('/api/v1/books')['meta']['total']);
    }

    public function testItPaginatesWithLinks(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->makeBook('Book Number ' . $i);
        }

        $first = $this->json('/api/v1/books');

        $this->assertCount(24, $first['data']);
        $this->assertSame(2, $first['meta']['pages']);
        $this->assertNull($first['links']['prev']);
        $this->assertStringContainsString('page=2', (string) $first['links']['next']);

        $second = $this->json('/api/v1/books?page=2');
        $this->assertCount(6, $second['data']);
        $this->assertNull($second['links']['next']);
    }

    public function testABookHasItsDetail(): void
    {
        $this->makeBook('Meditations', [
            'authors'     => ['Marcus Aurelius'],
            'categories'  => ['/religion/'],
            'tags'        => ['Philosophy'],
            'description' => 'Notes to himself.',
        ]);

        $payload = $this->json('/api/v1/books/meditations');

        $this->assertSame('Notes to himself.', $payload['data']['description']);
        $this->assertSame(['religion'], $payload['data']['categories']);
        $this->assertSame(['philosophy'], $payload['data']['tags']);
        $this->assertSame('public_domain', $payload['data']['licence']);
    }

    public function testAnUnknownBookIsJsonNotHtml(): void
    {
        $response = $this->get('/api/v1/books/nothing-here');

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('application/json', $response->headers()['Content-Type']);
        $this->assertSame(404, json_decode($response->body(), true)['status']);
    }

    public function testCategoriesAndTagsAreListed(): void
    {
        $this->makeBook('Meditations', ['categories' => ['/religion/'], 'tags' => ['Philosophy']]);

        $categories = $this->json('/api/v1/categories');
        $tags = $this->json('/api/v1/tags');

        $this->assertContains('religion', array_column($categories['data'], 'path'));
        $this->assertContains('philosophy', array_column($tags['data'], 'slug'));
    }

    public function testTheApiCountsAgainstARateLimit(): void
    {
        $response = $this->get('/api/v1/books');

        $this->assertSame('120', $response->headers()['X-RateLimit-Limit']);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $response->headers());
    }

    public function testTheRssFeedListsRecentBooks(): void
    {
        $this->makeBook('Meditations', ['authors' => ['Marcus Aurelius']]);

        $response = $this->get('/feed.rss');
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('application/rss+xml', $response->headers()['Content-Type']);
        $this->assertStringContainsString('<rss version="2.0"', $body);
        $this->assertStringContainsString('<title>Meditations</title>', $body);
        $this->assertStringContainsString('/books/meditations', $body);

        $this->assertNotFalse(simplexml_load_string($body), 'The feed should be well formed XML.');
    }

    public function testTheOpdsFeedIsWellFormedAndLinksTheFiles(): void
    {
        $target = $this->makeReadableBook('A Downloadable Book');

        $response = $this->get('/opds');
        $body = $response->body();

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('opds-catalog', $response->headers()['Content-Type']);
        $this->assertStringContainsString('http://opds-spec.org/acquisition', $body);
        $this->assertStringContainsString('/files/' . $target['file_id'], $body);
        $this->assertNotFalse(simplexml_load_string($body), 'The feed should be well formed XML.');
    }

    public function testTheFeedsEscapeWhatIsInThem(): void
    {
        $this->makeBook('Ampersands & "Quotes" <Everywhere>');

        $rss = $this->get('/feed.rss')->body();

        $this->assertStringNotContainsString('& "', $rss);
        $this->assertNotFalse(simplexml_load_string($rss));
        $this->assertNotFalse(simplexml_load_string($this->get('/opds')->body()));
    }

    public function testAFeedCanBeNarrowedToATag(): void
    {
        $this->makeBook('A Space Story', ['tags' => ['Science fiction']]);
        $this->makeBook('Something Else', ['tags' => ['History']]);

        $body = $this->get('/feed.rss?tag=science-fiction')->body();

        $this->assertStringContainsString('A Space Story', $body);
        $this->assertStringNotContainsString('Something Else', $body);
    }
}
