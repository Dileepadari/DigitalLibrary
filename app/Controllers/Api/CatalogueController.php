<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Book;
use App\Repositories\BookRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\TagRepository;

/**
 * The public read API.
 *
 * Everything here is what a guest can already see on the website, in JSON: no
 * token, no personal data, no writes. Anything that needs an account stays on
 * the web routes, where the session and the CSRF token live.
 */
final class CatalogueController
{
    private const PER_PAGE = 24;

    public function __construct(
        private readonly BookRepository $books,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
    ) {
    }

    public function books(Request $request): Response
    {
        $filters = [];

        foreach (['q', 'category', 'tag', 'language', 'type', 'sort'] as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $page = max(1, (int) $request->query('page', 1));
        $results = $this->books->search($filters, $page, self::PER_PAGE);

        return Response::json([
            'data' => array_map(fn (Book $book): array => $this->summarise($book), $results['rows']),
            'meta' => [
                'total'    => $results['total'],
                'page'     => $results['page'],
                'pages'    => $results['pages'],
                'per_page' => self::PER_PAGE,
            ],
            'links' => [
                'self' => $this->pageUrl($filters, $results['page']),
                'next' => $results['page'] < $results['pages']
                    ? $this->pageUrl($filters, $results['page'] + 1)
                    : null,
                'prev' => $results['page'] > 1 ? $this->pageUrl($filters, $results['page'] - 1) : null,
            ],
        ]);
    }

    public function book(Request $request): Response
    {
        $book = $this->books->findBySlug((string) $request->parameter('slug'));

        if ($book === null) {
            throw HttpException::notFound('No book with that address.');
        }

        return Response::json(['data' => $this->describe($book)]);
    }

    public function categories(Request $request): Response
    {
        return Response::json([
            'data' => array_map(
                static fn ($category): array => [
                    'name'  => $category->name,
                    'path'  => $category->relativePath(),
                    'depth' => $category->depth,
                    'books' => $category->bookCount,
                ],
                $this->categories->all()
            ),
        ]);
    }

    public function tags(Request $request): Response
    {
        return Response::json([
            'data' => array_map(
                static fn ($tag): array => [
                    'name'  => $tag->name,
                    'slug'  => $tag->slug,
                    'books' => $tag->usageCount,
                ],
                $this->tags->active(300)
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function summarise(Book $book): array
    {
        return [
            'title'     => $book->title,
            'subtitle'  => $book->subtitle,
            'slug'      => $book->slug,
            'authors'   => array_map(static fn ($author): string => $author->name, $book->authors),
            'year'      => $book->publishedYear,
            'language'  => $book->language,
            'type'      => $book->contentType->value,
            'formats'   => $book->formats(),
            'rating'    => $book->ratingCount > 0 ? round($book->ratingAverage, 2) : null,
            'ratings'   => $book->ratingCount,
            'url'       => '/books/' . $book->slug,
        ];
    }

    /** @return array<string, mixed> */
    private function describe(Book $book): array
    {
        return $this->summarise($book) + [
            'description' => $book->description,
            'publisher'   => $book->publisherName,
            'edition'     => $book->edition,
            'isbn'        => $book->isbn13 ?? $book->isbn10,
            'pages'       => $book->pageCount,
            'licence'     => $book->licence->value,
            'source_url'  => $book->sourceUrl,
            'categories'  => array_map(static fn ($category): string => $category->relativePath(), $book->categories),
            'tags'        => array_map(static fn ($tag): string => $tag->slug, $book->tags),
            'files'       => array_map(static fn ($file): array => [
                'format' => $file->format,
                'size'   => $file->sizeBytes,
                'pages'  => $file->pageCount,
            ], $book->publishedFiles()),
            'added_at'    => $book->publishedAt,
        ];
    }

    /** @param array<string, string> $filters */
    private function pageUrl(array $filters, int $page): string
    {
        return '/api/v1/books?' . http_build_query(array_merge($filters, ['page' => $page]));
    }
}
