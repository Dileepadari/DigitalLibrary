<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The subject types the queue handles today. Stored as a string, so adding one
 * is a code change rather than a schema change.
 */
enum ModerationType: string
{
    case BookUpload = 'book_upload';
    case BookRecord = 'book_record';
    case CategoryProposal = 'category_proposal';
    case CollectionPublish = 'collection_publish';

    public function label(): string
    {
        return match ($this) {
            self::BookUpload       => 'Book upload',
            self::BookRecord       => 'Book record',
            self::CategoryProposal => 'Category proposal',
            self::CollectionPublish => 'Collection',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
