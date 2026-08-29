<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BookFile;

/**
 * What came back from the upload pipeline: a stored file, or the reason it was
 * refused, in the words the uploader sees.
 */
final class UploadResult
{
    /** @param list<string> $warnings */
    private function __construct(
        public readonly bool $ok,
        public readonly ?BookFile $file = null,
        public readonly ?string $error = null,
        public readonly array $warnings = [],
        public readonly ?int $duplicateOf = null,
    ) {
    }

    /** @param list<string> $warnings */
    public static function stored(BookFile $file, array $warnings = []): self
    {
        return new self(true, $file, null, $warnings);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }

    /** The exact bytes are already in the library, so there is nothing to add. */
    public static function duplicate(string $error, int $bookId): self
    {
        return new self(false, null, $error, [], $bookId);
    }
}
