<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BookStatus;
use App\Support\ContentType;
use App\Support\Licence;

final class Book
{
    /** @var list<Author> */
    public array $authors = [];

    /** @var list<Category> */
    public array $categories = [];

    /** @var list<Tag> */
    public array $tags = [];

    /** @var list<BookFile> */
    public array $files = [];

    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $slug,
        public readonly ?string $publisherName,
        public readonly ?int $publishedYear,
        public readonly ?string $edition,
        public readonly string $language,
        public readonly ?string $isbn10,
        public readonly ?string $isbn13,
        public readonly ?string $description,
        public readonly ContentType $contentType,
        public readonly Licence $licence,
        public readonly ?string $licenceNote,
        public readonly ?string $sourceUrl,
        public readonly ?string $coverPath,
        public readonly ?int $pageCount,
        public readonly BookStatus $status,
        public readonly ?int $addedBy,
        public readonly ?string $publishedAt,
        public readonly int $viewCount,
        public readonly int $downloadCount,
        public readonly float $ratingAverage,
        public readonly int $ratingCount,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['title'],
            $row['subtitle'] !== null ? (string) $row['subtitle'] : null,
            (string) $row['slug'],
            isset($row['publisher_name']) ? (string) $row['publisher_name'] : null,
            $row['published_year'] !== null ? (int) $row['published_year'] : null,
            $row['edition'] !== null ? (string) $row['edition'] : null,
            (string) $row['language'],
            $row['isbn10'] !== null ? (string) $row['isbn10'] : null,
            $row['isbn13'] !== null ? (string) $row['isbn13'] : null,
            $row['description'] !== null ? (string) $row['description'] : null,
            ContentType::from((string) $row['content_type']),
            Licence::from((string) $row['licence']),
            $row['licence_note'] !== null ? (string) $row['licence_note'] : null,
            $row['source_url'] !== null ? (string) $row['source_url'] : null,
            $row['cover_path'] !== null ? (string) $row['cover_path'] : null,
            $row['page_count'] !== null ? (int) $row['page_count'] : null,
            BookStatus::from((string) $row['status']),
            $row['added_by'] !== null ? (int) $row['added_by'] : null,
            $row['published_at'] !== null ? (string) $row['published_at'] : null,
            (int) $row['view_count'],
            (int) $row['download_count'],
            (float) ($row['rating_average'] ?? 0),
            (int) ($row['rating_count'] ?? 0),
            (string) $row['created_at'],
        );
    }

    /** "Jane Austen and Mary Shelley", or "Unknown author" when there are none. */
    public function byline(): string
    {
        $names = array_map(
            static fn (Author $author): string => $author->name,
            array_filter($this->authors, static fn (Author $a): bool => $a->role === 'author')
        );

        if ($names === []) {
            return 'Unknown author';
        }

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    public function hasFiles(): bool
    {
        return $this->publishedFiles() !== [];
    }

    /** @return list<BookFile> */
    public function publishedFiles(): array
    {
        return array_values(array_filter($this->files, static fn (BookFile $f): bool => $f->isPublished()));
    }

    public function primaryFile(): ?BookFile
    {
        $published = $this->publishedFiles();

        foreach ($published as $file) {
            if ($file->isPrimary) {
                return $file;
            }
        }

        return $published[0] ?? null;
    }

    /** @return list<string> the distinct formats attached, uppercased for display */
    public function formats(): array
    {
        $formats = [];

        foreach ($this->publishedFiles() as $file) {
            $formats[strtoupper($file->format)] = true;
        }

        return array_keys($formats);
    }
}
