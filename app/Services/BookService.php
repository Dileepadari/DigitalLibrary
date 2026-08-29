<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\AuthorRepository;
use App\Repositories\BookRepository;
use App\Repositories\PublisherRepository;
use App\Repositories\TagRepository;
use App\Support\BookStatus;
use App\Support\ContentType;
use App\Support\Licence;
use App\Support\Slug;

/**
 * Creating and editing a catalogue record: slug, relations, status and the audit
 * entry, in one place so the librarian form and the upload pipeline (M3) cannot
 * do it differently.
 */
final class BookService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly AuthorRepository $authors,
        private readonly PublisherRepository $publishers,
        private readonly TagRepository $tags,
        private readonly AuditLogRepository $audit,
        private readonly Gate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, ?User $actor): Book
    {
        $title = trim((string) ($input['title'] ?? ''));

        // Someone with book.publish puts records straight into the catalogue;
        // anyone else queues them for review.
        $status = $this->gate->allows('book.publish') ? BookStatus::Published : BookStatus::Pending;

        $id = $this->books->create([
            'title'          => $title,
            'subtitle'       => $this->nullable($input['subtitle'] ?? null),
            'slug'           => Slug::unique($title, fn (string $s): bool => $this->books->slugTaken($s), 260),
            'publisher_id'   => $this->publishers->findOrCreate((string) ($input['publisher'] ?? '')),
            'published_year' => $this->year($input['published_year'] ?? null),
            'edition'        => $this->nullable($input['edition'] ?? null),
            'language'       => $this->language($input['language'] ?? null),
            'isbn10'         => $this->isbn($input['isbn10'] ?? null, 10),
            'isbn13'         => $this->isbn($input['isbn13'] ?? null, 13),
            'description'    => $this->nullable($input['description'] ?? null),
            'content_type'   => $this->contentType($input['content_type'] ?? null)->value,
            'licence'        => $this->licence($input['licence'] ?? null)->value,
            'licence_note'   => $this->nullable($input['licence_note'] ?? null),
            'source_url'     => $this->nullable($input['source_url'] ?? null),
            'page_count'     => $this->positiveInt($input['page_count'] ?? null),
            'status'         => $status->value,
            'added_by'       => $actor?->id,
            'published_at'   => $status === BookStatus::Published ? gmdate('Y-m-d H:i:s') : null,
        ]);

        $this->syncRelations($id, $input, $actor);

        $this->audit->record($actor?->id, 'book.created', 'book', $id, null, [
            'title'  => $title,
            'status' => $status->value,
        ]);

        $book = $this->books->findById($id);

        if ($book === null) {
            throw new \RuntimeException('The book was created but could not be read back.');
        }

        return $book;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(Book $book, array $input, ?User $actor): Book
    {
        $title = trim((string) ($input['title'] ?? $book->title));

        $values = [
            'title'          => $title,
            'subtitle'       => $this->nullable($input['subtitle'] ?? null),
            'publisher_id'   => $this->publishers->findOrCreate((string) ($input['publisher'] ?? '')),
            'published_year' => $this->year($input['published_year'] ?? null),
            'edition'        => $this->nullable($input['edition'] ?? null),
            'language'       => $this->language($input['language'] ?? null),
            'isbn10'         => $this->isbn($input['isbn10'] ?? null, 10),
            'isbn13'         => $this->isbn($input['isbn13'] ?? null, 13),
            'description'    => $this->nullable($input['description'] ?? null),
            'content_type'   => $this->contentType($input['content_type'] ?? null)->value,
            'licence'        => $this->licence($input['licence'] ?? null)->value,
            'licence_note'   => $this->nullable($input['licence_note'] ?? null),
            'source_url'     => $this->nullable($input['source_url'] ?? null),
            'page_count'     => $this->positiveInt($input['page_count'] ?? null),
        ];

        // The slug is part of every link to the record, so it only follows a
        // title change while the record is not public yet.
        if ($title !== $book->title && !$book->status->isPublic()) {
            $values['slug'] = Slug::unique($title, fn (string $s): bool => $this->books->slugTaken($s), 260);
        }

        $this->books->update($book->id, $values);
        $this->syncRelations($book->id, $input, $actor);

        $this->audit->record($actor?->id, 'book.updated', 'book', $book->id, [
            'title' => $book->title,
        ], ['title' => $title]);

        $updated = $this->books->findById($book->id);

        if ($updated === null) {
            throw new \RuntimeException('The book disappeared while it was being edited.');
        }

        return $updated;
    }

    public function setStatus(Book $book, BookStatus $status, ?User $actor): void
    {
        $this->books->setStatus($book->id, $status);
        $this->books->refreshCounters();

        $this->audit->record(
            $actor?->id,
            'book.status_changed',
            'book',
            $book->id,
            ['status' => $book->status->value],
            ['status' => $status->value]
        );
    }

    /** @param array<string, mixed> $input */
    private function syncRelations(int $bookId, array $input, ?User $actor): void
    {
        $this->books->syncAuthors(
            $bookId,
            $this->authors->resolveMany(self::split((string) ($input['authors'] ?? '')))
        );

        $categories = array_map('intval', (array) ($input['categories'] ?? []));
        $this->books->syncCategories($bookId, array_values(array_filter($categories)));

        // A librarian's tags are live immediately; a member's wait for approval.
        $this->books->syncTags($bookId, $this->tags->resolveMany(
            self::split((string) ($input['tags'] ?? '')),
            $actor?->id,
            $this->gate->allows('taxonomy.manage'),
        ));

        $this->books->refreshCounters();
    }

    /** @return list<string> */
    public static function split(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function year(mixed $value): ?int
    {
        $year = (int) $value;

        return $year >= 1000 && $year <= (int) date('Y') + 1 ? $year : null;
    }

    private function language(mixed $value): string
    {
        $language = strtolower(trim((string) ($value ?? '')));

        return preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $language) === 1 ? $language : 'en';
    }

    private function isbn(mixed $value, int $length): ?string
    {
        $isbn = preg_replace('/[^0-9Xx]/', '', (string) ($value ?? '')) ?? '';

        return strlen($isbn) === $length ? strtoupper($isbn) : null;
    }

    private function contentType(mixed $value): ContentType
    {
        return ContentType::tryFrom((string) ($value ?? '')) ?? ContentType::Book;
    }

    private function licence(mixed $value): Licence
    {
        return Licence::tryFrom((string) ($value ?? '')) ?? Licence::Unknown;
    }
}
