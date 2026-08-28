<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What kind of thing a record is. Decides which reader opens it (M6) and which
 * metadata fields the form shows.
 */
enum ContentType: string
{
    case Book = 'book';
    case Magazine = 'magazine';
    case Comic = 'comic';
    case AcademicPaper = 'academic_paper';
    case Notes = 'notes';
    case Audiobook = 'audiobook';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Book          => 'Book',
            self::Magazine      => 'Magazine',
            self::Comic         => 'Comic',
            self::AcademicPaper => 'Academic paper',
            self::Notes         => 'Notes',
            self::Audiobook     => 'Audiobook',
            self::Video         => 'Video',
        };
    }

    /**
     * Formats that make sense to attach to this kind of record.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        return match ($this) {
            self::Comic     => ['cbz', 'pdf'],
            self::Audiobook => ['mp3', 'm4b'],
            self::Video     => ['mp4'],
            default         => ['pdf', 'epub', 'mobi', 'djvu', 'txt'],
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
