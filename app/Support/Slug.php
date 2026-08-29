<?php

declare(strict_types=1);

namespace App\Support;

final class Slug
{
    /**
     * Lowercase, ASCII, hyphen separated. Non-Latin titles transliterate to
     * nothing, so the caller gets an empty string back and has to fall back to
     * something else (BookService uses the record id).
     */
    public static function make(string $value, int $maxLength = 120): string
    {
        $slug = $value;

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
            $slug = $converted === false ? $slug : $converted;
        }

        $slug = strtolower($slug);

        // Apostrophes close up rather than becoming a separator: "alice's" is
        // one word, and "alice-s" reads as a typo in a URL.
        $slug = (string) preg_replace('/[\x27\x60\xE2\x80\x99]+/u', '', $slug);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        if (mb_strlen($slug) > $maxLength) {
            $slug = rtrim(mb_substr($slug, 0, $maxLength), '-');
        }

        return $slug;
    }

    /**
     * Appends -2, -3 and so on until $isTaken says the slug is free.
     */
    public static function unique(string $value, callable $isTaken, int $maxLength = 120): string
    {
        $base = self::make($value, $maxLength);

        if ($base === '') {
            $base = 'item';
        }

        $slug = $base;
        $suffix = 1;

        while ($isTaken($slug)) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
