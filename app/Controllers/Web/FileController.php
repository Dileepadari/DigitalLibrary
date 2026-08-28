<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Services\Auth;
use App\Services\Gate;

/**
 * The only way bytes leave storage/.
 *
 * Nothing under storage/ is reachable by URL, so every read comes through here:
 * permission first, then the log, then the bytes. Range requests are honoured so
 * a reader can seek and an interrupted download can resume, which is also what
 * the in-browser readers will need in M6.
 */
final class FileController
{
    private const MIME = [
        'pdf'  => 'application/pdf',
        'epub' => 'application/epub+zip',
        'mobi' => 'application/x-mobipocket-ebook',
        'djvu' => 'image/vnd.djvu',
        'cbz'  => 'application/zip',
        'txt'  => 'text/plain; charset=UTF-8',
    ];

    public function __construct(
        private readonly BookFileRepository $files,
        private readonly BookRepository $books,
        private readonly Storage $storage,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
    }

    public function show(Request $request): Response
    {
        $this->gate->authorize('book.download');

        $file = $this->files->findById((int) $request->parameter('id'));

        if ($file === null) {
            throw HttpException::notFound('No such file.');
        }

        // A quarantined file exists only for the reviewer looking at it and for
        // the person who uploaded it.
        if (!$file->isPublished()) {
            $user = $this->auth->user();
            $isUploader = $user !== null && $file->uploadedBy === $user->id;

            if (!$this->gate->allows('moderation.queue') && !$isUploader) {
                throw HttpException::notFound('No such file.');
            }
        }

        if (!$this->storage->exists($file->storagePath)) {
            throw HttpException::notFound('That file is missing from storage.');
        }

        $book = $this->books->findById($file->bookId);
        $filename = $this->downloadName($book === null ? 'book' : $book->slug, $file->format);

        if ($file->isPublished()) {
            $this->files->incrementDownloads($file->id);
        }

        return $this->stream(
            $this->storage->absolute($file->storagePath),
            self::MIME[$file->format] ?? 'application/octet-stream',
            $filename,
            $request->header('range'),
            $request->query('inline') !== null,
        );
    }

    /**
     * Streams the file in chunks rather than reading it into memory, and answers
     * a Range request with a 206 so seeking works.
     */
    private function stream(
        string $path,
        string $mime,
        string $filename,
        ?string $range,
        bool $inline,
    ): Response {
        $size = (int) filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;

        if ($range !== null && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches) === 1) {
            $requestedStart = $matches[1] === '' ? null : (int) $matches[1];
            $requestedEnd = $matches[2] === '' ? null : (int) $matches[2];

            if ($requestedStart === null && $requestedEnd !== null) {
                // "bytes=-500" means the last 500 bytes.
                $start = max(0, $size - $requestedEnd);
            } else {
                $start = $requestedStart ?? 0;
                $end = $requestedEnd ?? $end;
            }

            if ($start > $end || $start >= $size) {
                return (new Response('', 416))->withHeader('Content-Range', 'bytes */' . $size);
            }

            $end = min($end, $size - 1);
            $status = 206;
        }

        $length = $end - $start + 1;
        $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"';

        // The body is produced by a callback rather than a string: a 400 MB scan
        // must not have to fit in memory.
        $response = new Response('', $status, [
            'Content-Type'        => $mime,
            'Content-Length'      => (string) $length,
            'Content-Disposition' => $disposition,
            'Accept-Ranges'       => 'bytes',
            'Cache-Control'       => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($status === 206) {
            $response->withHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size);
        }

        return $response->withStream(static function () use ($path, $start, $length): void {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            fseek($handle, $start);
            $remaining = $length;

            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, (int) min(262144, $remaining));

                if ($chunk === false) {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }

            fclose($handle);
        });
    }

    private function downloadName(string $slug, string $format): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $slug . '.' . $format) ?? 'book.' . $format;
    }
}
