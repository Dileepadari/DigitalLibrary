<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Interface strings, by locale.
 *
 * The key is the English string, so a missing translation renders as readable
 * English rather than as `nav.browse`, and a template that has not been touched
 * yet still says something sensible. The catalogue itself is not translated:
 * a book's title is its title.
 */
final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $loaded = [];

    private string $locale;

    public function __construct(
        private readonly string $directory,
        string $locale = 'en',
        private readonly string $fallback = 'en',
    ) {
        $this->locale = $this->available($locale) ? $locale : $fallback;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if ($this->available($locale)) {
            $this->locale = $locale;
        }
    }

    public function available(string $locale): bool
    {
        return preg_match('/^[a-z]{2}$/', $locale) === 1
            && is_file($this->directory . '/' . $locale . '.php');
    }

    /** @return list<string> */
    public function locales(): array
    {
        $found = [];

        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            $found[] = basename($file, '.php');
        }

        sort($found);

        return $found;
    }

    /**
     * @param array<string, string|int> $replacements filled into :name placeholders
     */
    public function get(string $key, array $replacements = []): string
    {
        $line = $this->lines($this->locale)[$key]
            ?? $this->lines($this->fallback)[$key]
            ?? $key;

        foreach ($replacements as $name => $value) {
            $line = str_replace(':' . $name, (string) $value, $line);
        }

        return $line;
    }

    /** @return array<string, string> */
    private function lines(string $locale): array
    {
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }

        $path = $this->directory . '/' . $locale . '.php';
        $lines = is_file($path) ? require $path : [];

        return $this->loaded[$locale] = is_array($lines) ? $lines : [];
    }
}
