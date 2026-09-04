<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Storage;

/**
 * Makes a cover image out of the first page of a PDF, for the many uploads that
 * arrive without one.
 *
 * PHP cannot rasterise a PDF on its own, so this uses whatever the host has, in
 * order of preference:
 *
 *   1. the Imagick extension (which itself delegates to Ghostscript)
 *   2. pdftoppm, from poppler-utils
 *   3. Ghostscript directly
 *
 * If the host has none of them, covers are simply not generated: an upload must
 * never fail because a picture could not be made of it. `covers:generate`
 * reports which renderer, if any, is in use.
 */
final class CoverGenerator
{
    /** Wide enough for the book page, small enough to stay a thumbnail. */
    private const WIDTH = 600;

    private const QUALITY = 82;

    /** A malformed PDF can send a rasteriser into a very long loop. */
    private const TIMEOUT_SECONDS = 20;

    /** @var array<string, string|null> */
    private array $binaries = [];

    public function __construct(
        private readonly Storage $storage,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Every renderer this host could use, best first.
     *
     * Presence is not ability: Imagick delegates PDF rasterising to Ghostscript
     * and many distributions ship one without the other, or ship an ImageMagick
     * policy.xml that refuses the PDF coder outright. Such a host reports
     * Imagick, renders nothing, and the failure looks like a bug in this class.
     * So the list is a list, and `fromPdf` tries the next one when a renderer
     * returns nothing.
     *
     * @return list<string>
     */
    public function renderers(): array
    {
        $available = [];

        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            $available[] = 'imagick';
        }

        if ($this->binary('pdftoppm') !== null) {
            $available[] = 'pdftoppm';
        }

        if ($this->binary('gs') !== null) {
            $available[] = 'ghostscript';
        }

        return $available;
    }

    /** The renderer that would be tried first, or null when there is none. */
    public function renderer(): ?string
    {
        return $this->renderers()[0] ?? null;
    }

    public function available(): bool
    {
        return $this->renderer() !== null;
    }

    /**
     * @param string $absolutePath the PDF on disk
     * @param string $sha256       names the cover, so it matches its file
     *
     * @return string|null the relative storage path, or null when no cover could be made
     */
    public function fromPdf(string $absolutePath, string $sha256): ?string
    {
        if (!is_file($absolutePath)) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'dlcover');

        if ($temporary === false) {
            return null;
        }

        try {
            $rendered = false;

            foreach ($this->renderers() as $renderer) {
                $rendered = match ($renderer) {
                    'imagick'     => $this->withImagick($absolutePath, $temporary),
                    'pdftoppm'    => $this->withPdftoppm($absolutePath, $temporary),
                    'ghostscript' => $this->withGhostscript($absolutePath, $temporary),
                    default       => false,
                };

                if ($rendered && $this->isImage($temporary)) {
                    break;
                }

                // Leave nothing behind for the next renderer to mistake for output.
                $rendered = false;

                if (is_file($temporary)) {
                    file_put_contents($temporary, '');
                }
            }

            if (!$rendered || !$this->isImage($temporary)) {
                return null;
            }

            $this->resize($temporary);

            $relative = 'covers/' . $this->storage->shardedName($sha256, 'jpg');
            $this->storage->put($relative, $temporary);

            return $relative;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not render a cover', [
                'error'      => $e->getMessage(),
                'renderers'  => $this->renderers(),
            ]);

            return null;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function withImagick(string $source, string $target): bool
    {
        try {
            $image = new \Imagick();
            $image->setResolution(120, 120);
            // Page one only: reading a 900 page scan in full would be absurd.
            $image->readImage($source . '[0]');
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::QUALITY);
            $image->setImageBackgroundColor('white');
            $flattened = $image->flattenImages();
            $written = $flattened->writeImage($target);
            $image->clear();
            $flattened->clear();

            return $written;
        } catch (\Throwable $e) {
            // A default ImageMagick policy blocks PDFs outright; fall through
            // to the command line tools rather than failing the upload.
            $this->logger->warning('Imagick could not read the PDF', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function withPdftoppm(string $source, string $target): bool
    {
        $prefix = $target . '-page';

        $this->run([
            (string) $this->binary('pdftoppm'),
            '-jpeg',
            '-singlefile',
            '-f', '1',
            '-l', '1',
            '-scale-to-x', (string) self::WIDTH,
            '-scale-to-y', '-1',
            $source,
            $prefix,
        ]);

        if (!is_file($prefix . '.jpg')) {
            return false;
        }

        $moved = @rename($prefix . '.jpg', $target);

        if (!$moved) {
            @unlink($prefix . '.jpg');
        }

        return $moved;
    }

    private function withGhostscript(string $source, string $target): bool
    {
        $this->run([
            (string) $this->binary('gs'),
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=jpeg',
            '-dJPEGQ=' . self::QUALITY,
            '-dFirstPage=1',
            '-dLastPage=1',
            '-r100',
            '-sOutputFile=' . $target,
            $source,
        ]);

        return is_file($target) && filesize($target) > 0;
    }

    /**
     * Runs a rasteriser with every argument escaped and a hard time limit. The
     * only paths that reach here are ours (a hash under storage/ and a temporary
     * file), never anything a user typed.
     *
     * @param list<string> $arguments
     */
    private function run(array $arguments): void
    {
        $command = implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>/dev/null';
        $timeout = $this->binary('timeout');

        if ($timeout !== null) {
            $command = escapeshellarg($timeout) . ' ' . self::TIMEOUT_SECONDS . ' ' . $command;
        }

        exec($command, $output, $status);

        if ($status !== 0) {
            $this->logger->warning('A cover renderer exited badly', [
                'status'  => $status,
                'command' => $arguments[0],
            ]);
        }
    }

    /** Scales the rendered page down to the cover width and re-encodes it. */
    private function resize(string $path): void
    {
        $size = getimagesize($path);

        if ($size === false || !function_exists('imagecreatefromjpeg')) {
            return;
        }

        [$width, $height] = $size;

        if ($width <= self::WIDTH) {
            return;
        }

        $source = @imagecreatefromjpeg($path);

        if ($source === false) {
            return;
        }

        $targetHeight = (int) round($height * (self::WIDTH / $width));
        $resized = imagecreatetruecolor(self::WIDTH, $targetHeight);

        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $source, 0, 0, 0, 0, self::WIDTH, $targetHeight, $width, $height);
        imagejpeg($resized, $path, self::QUALITY);

        imagedestroy($source);
        imagedestroy($resized);
    }

    private function isImage(string $path): bool
    {
        return is_file($path) && filesize($path) > 0 && getimagesize($path) !== false;
    }

    private function binary(string $name): ?string
    {
        if (array_key_exists($name, $this->binaries)) {
            return $this->binaries[$name];
        }

        $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));

        return $this->binaries[$name] = $path === '' ? null : $path;
    }
}
