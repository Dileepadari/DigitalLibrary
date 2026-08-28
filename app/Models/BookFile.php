<?php

declare(strict_types=1);

namespace App\Models;

final class BookFile
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookId,
        public readonly string $format,
        public readonly ?string $originalName,
        public readonly ?string $mimeType,
        public readonly string $storagePath,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        public readonly ?int $pageCount,
        public readonly string $quality,
        public readonly string $status,
        public readonly bool $isPrimary,
        public readonly int $downloadCount,
        public readonly ?int $uploadedBy = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['book_id'],
            (string) $row['format'],
            isset($row['original_name']) ? (string) $row['original_name'] : null,
            isset($row['mime_type']) ? (string) $row['mime_type'] : null,
            (string) $row['storage_path'],
            (string) $row['sha256'],
            (int) $row['size_bytes'],
            $row['page_count'] !== null ? (int) $row['page_count'] : null,
            (string) $row['quality'],
            (string) ($row['status'] ?? 'quarantined'),
            (bool) $row['is_primary'],
            (int) $row['download_count'],
            isset($row['uploaded_by']) ? (int) $row['uploaded_by'] : null,
        );
    }

    /**
     * Formats the in-browser reader can open. Everything else is a download:
     * pretending otherwise would just be a blank page with a spinner.
     */
    public function isReadable(): bool
    {
        return in_array($this->format, ['pdf', 'epub', 'txt'], true) && $this->isPublished();
    }

    public function readerKind(): string
    {
        return match ($this->format) {
            'pdf'   => 'pdf',
            'epub'  => 'epub',
            'txt'   => 'text',
            default => 'none',
        };
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->sizeBytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $size < 10 && $unit > 0 ? 1 : 0) . ' ' . $units[$unit];
    }
}
