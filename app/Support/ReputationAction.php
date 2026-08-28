<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The things that earn reputation, and what each is worth.
 *
 * Every award goes through one of these rather than a number typed at the call
 * site, so the scale can be read in one place and a badge can count the events
 * of one kind.
 */
enum ReputationAction: string
{
    case UploadAccepted = 'upload.accepted';
    case RequestFulfilled = 'request.fulfilled';
    case ReviewWritten = 'review.written';
    case ReviewHelpful = 'review.helpful';
    case CollectionPublished = 'collection.published';
    case ModerationDecided = 'moderation.decided';

    public function points(): int
    {
        return match ($this) {
            self::UploadAccepted      => 5,
            self::RequestFulfilled    => 10,
            self::ReviewWritten       => 2,
            self::ReviewHelpful       => 1,
            self::CollectionPublished => 8,
            self::ModerationDecided   => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UploadAccepted      => 'Upload accepted',
            self::RequestFulfilled    => 'Answered a request',
            self::ReviewWritten       => 'Wrote a review',
            self::ReviewHelpful       => 'A review was found helpful',
            self::CollectionPublished => 'A collection was published',
            self::ModerationDecided   => 'Decided a queue item',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
