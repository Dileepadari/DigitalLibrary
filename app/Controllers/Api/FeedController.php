<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Models\Book;
use App\Repositories\BookRepository;
use App\Repositories\SettingsRepository;

/**
 * Feeds: RSS for people who follow new arrivals in a reader, and OPDS for
 * e-reader apps, which is the same catalogue in the format they understand.
 *
 * Both are built by hand rather than with a library: they are two dozen lines
 * of XML and a dependency would be the larger thing.
 */
final class FeedController
{
    private const LIMIT = 40;

    public function __construct(
        private readonly BookRepository $books,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    public function rss(Request $request): Response
    {
        $filters = $this->filters($request);
        $books = $this->books->search($filters, 1, self::LIMIT)['rows'];
        $title = $this->title($filters);
        $self = $this->base() . '/feed.rss' . ($filters === [] ? '' : '?' . http_build_query($filters));

        $items = '';

        foreach ($books as $book) {
            $items .= "        <item>\n"
                . '            <title>' . $this->xml($book->title) . "</title>\n"
                . '            <link>' . $this->xml($this->base() . '/books/' . $book->slug) . "</link>\n"
                . '            <guid isPermaLink="true">'
                . $this->xml($this->base() . '/books/' . $book->slug) . "</guid>\n"
                . '            <pubDate>' . $this->rfc822($book->publishedAt ?? $book->createdAt) . "</pubDate>\n"
                . '            <description>' . $this->xml($this->summary($book)) . "</description>\n"
                . "        </item>\n";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "    <channel>\n"
            . '        <title>' . $this->xml($title) . "</title>\n"
            . '        <link>' . $this->xml($this->base() . '/books') . "</link>\n"
            . '        <description>' . $this->xml($this->settings->string(
                'site.tagline',
                (string) $this->config->get('app.tagline')
            )) . "</description>\n"
            . '        <atom:link href="' . $this->xml($self) . '" rel="self" type="application/rss+xml"/>' . "\n"
            . '        <lastBuildDate>' . $this->rfc822(gmdate('Y-m-d H:i:s')) . "</lastBuildDate>\n"
            . $items
            . "    </channel>\n</rss>\n";

        return $this->xmlResponse($xml, 'application/rss+xml');
    }

    /**
     * The OPDS acquisition feed. Downloading needs an account, so the links
     * point at the book pages rather than the files: an e-reader can browse the
     * catalogue, and a person signs in to fetch.
     */
    public function opds(Request $request): Response
    {
        $filters = $this->filters($request);
        $books = $this->books->search($filters, 1, self::LIMIT)['rows'];
        $title = $this->title($filters);

        $entries = '';

        foreach ($books as $book) {
            $entries .= "    <entry>\n"
                . '        <title>' . $this->xml($book->title) . "</title>\n"
                . '        <id>urn:book:' . $this->xml($book->slug) . "</id>\n"
                . '        <updated>' . $this->rfc3339($book->publishedAt ?? $book->createdAt) . "</updated>\n"
                . '        <author><name>' . $this->xml($book->byline()) . "</name></author>\n"
                . '        <content type="text">' . $this->xml($this->summary($book)) . "</content>\n"
                . '        <link rel="alternate" type="text/html" href="'
                . $this->xml($this->base() . '/books/' . $book->slug) . '"/>' . "\n";

            foreach ($book->publishedFiles() as $file) {
                $entries .= '        <link rel="http://opds-spec.org/acquisition" type="'
                    . $this->xml($this->mime($file->format)) . '" href="'
                    . $this->xml($this->base() . '/files/' . $file->id) . '"/>' . "\n";
            }

            $entries .= "    </entry>\n";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">' . "\n"
            . '    <id>' . $this->xml($this->base() . '/opds') . "</id>\n"
            . '    <title>' . $this->xml($title) . "</title>\n"
            . '    <updated>' . $this->rfc3339(gmdate('Y-m-d H:i:s')) . "</updated>\n"
            . '    <link rel="self" type="application/atom+xml;profile=opds-catalog" href="'
            . $this->xml($this->base() . '/opds') . '"/>' . "\n"
            . '    <link rel="start" type="application/atom+xml;profile=opds-catalog" href="'
            . $this->xml($this->base() . '/opds') . '"/>' . "\n"
            . $entries
            . "</feed>\n";

        return $this->xmlResponse($xml, 'application/atom+xml;profile=opds-catalog;kind=acquisition');
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['category', 'tag', 'q'] as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /** @param array<string, string> $filters */
    private function title(array $filters): string
    {
        $name = $this->settings->string('site.name', (string) $this->config->get('app.name'));

        if (isset($filters['category'])) {
            return $name . ': ' . $filters['category'];
        }

        if (isset($filters['tag'])) {
            return $name . ': ' . $filters['tag'];
        }

        if (isset($filters['q'])) {
            return $name . ': ' . $filters['q'];
        }

        return $name . ': recently added';
    }

    private function summary(Book $book): string
    {
        $parts = [$book->byline()];

        if ($book->publishedYear !== null) {
            $parts[] = (string) $book->publishedYear;
        }

        if ($book->description !== null) {
            $parts[] = mb_substr($book->description, 0, 300);
        }

        return implode(' - ', $parts);
    }

    private function mime(string $format): string
    {
        return match ($format) {
            'pdf'   => 'application/pdf',
            'epub'  => 'application/epub+zip',
            'mobi'  => 'application/x-mobipocket-ebook',
            'txt'   => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    private function base(): string
    {
        return rtrim((string) $this->config->get('app.url'), '/');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function rfc822(string $timestamp): string
    {
        return gmdate('D, d M Y H:i:s O', strtotime($timestamp) ?: time());
    }

    private function rfc3339(string $timestamp): string
    {
        return gmdate('c', strtotime($timestamp) ?: time());
    }

    private function xmlResponse(string $xml, string $type): Response
    {
        return new Response($xml, 200, [
            'Content-Type'   => $type . '; charset=UTF-8',
            'Content-Length' => (string) strlen($xml),
            'Cache-Control'  => 'public, max-age=900',
        ]);
    }
}
