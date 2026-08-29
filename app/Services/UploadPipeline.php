<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Storage;
use App\Models\Book;
use App\Models\User;
use App\Repositories\BookFileRepository;
use App\Repositories\BookRepository;
use App\Repositories\SettingsRepository;
use App\Support\UploadResult;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Everything between "someone chose a file" and "a reviewer can look at it",
 * in the order of PLAN.md section 6.
 *
 * Nothing here trusts the browser: not the filename, not the extension, not the
 * declared MIME type, not the size.
 */
final class UploadPipeline
{
    /**
     * Extension to the MIME types finfo may report for it. EPUB and CBZ are ZIP
     * containers, so a plain `application/zip` is expected rather than suspicious.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'pdf'  => ['application/pdf'],
        'epub' => ['application/epub+zip', 'application/zip'],
        'mobi' => ['application/x-mobipocket-ebook', 'application/octet-stream'],
        'djvu' => ['image/vnd.djvu', 'image/x-djvu', 'application/octet-stream'],
        'cbz'  => ['application/zip', 'application/x-cbr', 'application/x-cbz'],
        'txt'  => ['text/plain'],
    ];

    /** Anything that a web server or a reader could be talked into executing. */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'phar', 'html', 'htm', 'js', 'svg', 'exe', 'sh', 'bat', 'jar', 'py',
    ];

    public function __construct(
        private readonly Storage $storage,
        private readonly BookFileRepository $files,
        private readonly BookRepository $books,
        private readonly CoverGenerator $covers,
        private readonly Config $config,
        private readonly SettingsRepository $settings,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $upload one entry of $_FILES
     */
    public function receive(array $upload, Book $book, User $uploader): UploadResult
    {
        $error = $this->checkUploadError($upload);

        if ($error !== null) {
            return UploadResult::failed($error);
        }

        $temporary = (string) $upload['tmp_name'];
        $originalName = (string) ($upload['name'] ?? 'upload');
        $size = (int) $upload['size'];

        // The admin setting wins over the environment default, so a limit can be
        // changed without a deploy.
        $maximum = (int) $this->settings->get(
            'uploads.max_bytes',
            (int) $this->config->get('storage.max_upload_bytes', 209715200)
        );

        if ($size > $maximum) {
            return UploadResult::failed(
                'That file is ' . $this->humanSize($size) . '. The limit is ' . $this->humanSize($maximum) . '.'
            );
        }

        if ($uploader->storageUsed + $size > $uploader->storageQuota) {
            return UploadResult::failed(
                'That would put you over your upload quota of ' . $this->humanSize($uploader->storageQuota) . '.'
            );
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            return UploadResult::failed('That kind of file is never accepted here.');
        }

        if (!array_key_exists($extension, self::ALLOWED)) {
            return UploadResult::failed(
                'Accepted formats are ' . implode(', ', array_keys(self::ALLOWED)) . '.'
            );
        }

        $mime = $this->mimeOf($temporary);

        if (!in_array($mime, self::ALLOWED[$extension], true)) {
            return UploadResult::failed(
                'That file says it is a ' . $extension . ' but its contents are ' . $mime . '.'
            );
        }

        if ($extension === 'pdf' && $this->hasActiveContent($temporary)) {
            return UploadResult::failed(
                'That PDF carries an embedded script or launch action, so it cannot be accepted. '
                . 'Re-save it without active content and try again.'
            );
        }

        $sha256 = $this->storage->hash($temporary);
        $existing = $this->files->findByHash($sha256);

        if ($existing !== null) {
            return UploadResult::duplicate(
                'These exact bytes are already in the library.',
                $existing['book_id']
            );
        }

        $metadata = $this->inspect($temporary, $extension);
        $relative = $this->storage->quarantine($temporary, $sha256, $extension);

        $id = $this->files->create([
            'book_id'       => $book->id,
            'format'        => $extension,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime_type'     => $mime,
            'storage_path'  => $relative,
            'sha256'        => $sha256,
            'size_bytes'    => $size,
            'page_count'    => $metadata['pages'],
            'quality'       => 'unknown',
            'status'        => 'quarantined',
            'is_primary'    => $this->files->countFor($book->id) === 0 ? 1 : 0,
            'uploaded_by'   => $uploader->id,
        ]);

        if ($metadata['text'] !== null) {
            $this->files->storeText($book->id, $metadata['text']);
        }

        // Most uploads arrive without a cover. The first page is a better
        // stand-in than a coloured rectangle, and it costs one render.
        if ($extension === 'pdf' && $book->coverPath === null) {
            $cover = $this->covers->fromPdf($this->storage->absolute($relative), $sha256);

            if ($cover !== null) {
                $this->books->update($book->id, ['cover_path' => $cover]);
            }
        }

        $file = $this->files->findById($id);

        if ($file === null) {
            throw new \RuntimeException('The file was stored but could not be read back.');
        }

        return UploadResult::stored($file, $metadata['warnings']);
    }

    /** @param array<string, mixed> $upload */
    private function checkUploadError(array $upload): ?string
    {
        $code = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        return match ($code) {
            UPLOAD_ERR_OK        => null,
            UPLOAD_ERR_NO_FILE   => 'No file was chosen.',
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'That file is larger than this server accepts.',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
            default              => 'The upload failed (code ' . $code . ').',
        };
    }

    private function mimeOf(string $path): string
    {
        $info = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $info->file($path);

        return $mime === false ? 'application/octet-stream' : $mime;
    }

    /**
     * A PDF may carry JavaScript, an auto-run action or an embedded launch. A
     * reader that honours them turns a library into a delivery mechanism, so
     * they are refused rather than flagged.
     */
    private function hasActiveContent(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $found = false;

        // Read in overlapping chunks so a marker split across a boundary is
        // still seen.
        $previous = '';

        while (!feof($handle)) {
            $chunk = (string) fread($handle, 1048576);
            $window = $previous . $chunk;

            foreach (['/JavaScript', '/JS ', '/JS/', '/Launch', '/OpenAction'] as $marker) {
                if (str_contains($window, $marker)) {
                    $found = true;

                    break 2;
                }
            }

            $previous = substr($chunk, -32);
        }

        fclose($handle);

        return $found;
    }

    /**
     * Page count and the first few thousand words, for the search index.
     * Everything here is best effort: a file that cannot be parsed is still a
     * perfectly good file.
     *
     * @return array{pages: int|null, text: string|null, warnings: list<string>}
     */
    private function inspect(string $path, string $extension): array
    {
        $result = ['pages' => null, 'text' => null, 'warnings' => []];

        if ($extension === 'txt') {
            $result['text'] = mb_substr((string) file_get_contents($path), 0, 60000);

            return $result;
        }

        if ($extension !== 'pdf' || !class_exists(PdfParser::class)) {
            if ($extension === 'pdf') {
                $result['warnings'][] = 'Page count not read: smalot/pdfparser is not installed.';
            }

            return $result;
        }

        try {
            $pdf = (new PdfParser())->parseFile($path);
            $result['pages'] = count($pdf->getPages());
            $result['text'] = mb_substr(trim($pdf->getText()), 0, 60000);

            if ($result['text'] === '') {
                $result['warnings'][] = 'No text layer: this looks like a scan, so it will not be searchable.';
                $result['text'] = null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not parse an uploaded PDF', ['error' => $e->getMessage()]);
            $result['warnings'][] = 'The PDF could not be parsed, so there is no page count.';
        }

        return $result;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit > 1 ? 1 : 0) . ' ' . $units[$unit];
    }
}
