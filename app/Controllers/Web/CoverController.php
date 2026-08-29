<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Repositories\BookRepository;
use App\Services\Auth;
use App\Services\Gate;

/**
 * Serves a book's cover image.
 *
 * Covers sit in storage/covers with everything else, so they cannot be linked
 * to directly. They are public for a published book: a cover is catalogue
 * metadata, not the book.
 */
final class CoverController
{
    private const CACHE_SECONDS = 604800;

    public function __construct(
        private readonly BookRepository $books,
        private readonly Storage $storage,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
    }

    public function show(Request $request): Response
    {
        $book = $this->books->findById((int) $request->parameter('id'));

        if ($book === null || $book->coverPath === null) {
            throw HttpException::notFound('No cover for that book.');
        }

        if (!$book->status->isPublic()) {
            $user = $this->auth->user();
            $isOwn = $user !== null && $book->addedBy === $user->id;

            if (!$this->gate->allows('moderation.queue') && !$isOwn) {
                throw HttpException::notFound('No cover for that book.');
            }
        }

        if (!$this->storage->exists($book->coverPath)) {
            throw HttpException::notFound('That cover is missing from storage.');
        }

        $path = $this->storage->absolute($book->coverPath);

        return (new Response('', 200, [
            'Content-Type'           => 'image/jpeg',
            'Content-Length'         => (string) filesize($path),
            'Cache-Control'          => 'public, max-age=' . self::CACHE_SECONDS,
            'X-Content-Type-Options' => 'nosniff',
        ]))->withStream(static function () use ($path): void {
            readfile($path);
        });
    }
}
