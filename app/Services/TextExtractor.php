<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Pulls the readable text and the page count out of a file, so the words inside
 * a book can be searched and not just its metadata.
 *
 * Used by the upload pipeline when a file arrives and by `search:reindex` when
 * the index has to be rebuilt, which is why it is not buried in the pipeline.
 */
final class TextExtractor
{
    /** Enough to search on without turning the database into a copy of the library. */
    private const MAX_CHARACTERS = 60000;

    public function __construct(private readonly Logger $logger)
    {
    }

    public function available(): bool
    {
        return class_exists(PdfParser::class);
    }

    /**
     * @return array{pages: int|null, text: string|null, warnings: list<string>}
     */
    public function fromFile(string $path, string $format): array
    {
        $result = ['pages' => null, 'text' => null, 'warnings' => []];

        if (!is_file($path)) {
            return $result;
        }

        if ($format === 'txt') {
            $result['text'] = mb_substr((string) file_get_contents($path), 0, self::MAX_CHARACTERS);

            return $result;
        }

        if ($format !== 'pdf') {
            return $result;
        }

        if (!$this->available()) {
            $result['warnings'][] = 'Page count not read: smalot/pdfparser is not installed.';

            return $result;
        }

        try {
            $pdf = (new PdfParser())->parseFile($path);
            $result['pages'] = count($pdf->getPages());
            $text = mb_substr(trim($pdf->getText()), 0, self::MAX_CHARACTERS);

            if ($text === '') {
                // A scan with no text layer is a perfectly good book and a
                // useless search result; say so rather than pretending.
                $result['warnings'][] = 'No text layer: this looks like a scan, so it will not be searchable.';
            } else {
                $result['text'] = $text;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not parse a PDF', ['error' => $e->getMessage(), 'path' => $path]);
            $result['warnings'][] = 'The PDF could not be parsed, so there is no page count.';
        }

        return $result;
    }
}
