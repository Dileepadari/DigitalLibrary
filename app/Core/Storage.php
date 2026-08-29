<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The private file store. Nothing under it is inside the webroot, so every read
 * goes through a controller that checks permissions first.
 *
 * Paths in the database are relative (`quarantine/ab/cd/<sha>.pdf`) and are
 * resolved here. Files are sharded two levels deep by their hash so no directory
 * ends up with a hundred thousand entries.
 */
final class Storage
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function root(): string
    {
        return rtrim((string) $this->config['root'], '/');
    }

    /** `ab/cd/<sha>.pdf` */
    public function shardedName(string $sha256, string $extension): string
    {
        return substr($sha256, 0, 2) . '/' . substr($sha256, 2, 2) . '/' . $sha256 . '.' . $extension;
    }

    public function absolute(string $relative): string
    {
        return $this->root() . '/' . ltrim($relative, '/');
    }

    public function exists(string $relative): bool
    {
        return is_file($this->absolute($relative));
    }

    public function size(string $relative): int
    {
        $size = @filesize($this->absolute($relative));

        return $size === false ? 0 : $size;
    }

    /**
     * Moves an uploaded temporary file into quarantine.
     *
     * @return string the relative path
     */
    public function quarantine(string $temporaryPath, string $sha256, string $extension): string
    {
        $relative = 'quarantine/' . $this->shardedName($sha256, $extension);
        $target = $this->absolute($relative);

        $this->ensureDirectory(dirname($target));

        // move_uploaded_file only accepts a real upload; the CLI and the tests
        // hand over an ordinary file, so fall back to rename.
        $moved = is_uploaded_file($temporaryPath)
            ? move_uploaded_file($temporaryPath, $target)
            : rename($temporaryPath, $target);

        if (!$moved) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }

        chmod($target, 0640);

        return $relative;
    }

    /**
     * Publishes a quarantined file into the library.
     *
     * A hard link first: approving a file should not copy gigabytes, and the
     * link keeps the bytes alive if anything still points at the old path. On a
     * filesystem that refuses (or across devices) it falls back to a rename.
     */
    public function publish(string $quarantineRelative, string $sha256, string $extension): string
    {
        $relative = 'library/' . $this->shardedName($sha256, $extension);
        $source = $this->absolute($quarantineRelative);
        $target = $this->absolute($relative);

        if (!is_file($source)) {
            throw new \RuntimeException('The quarantined file is missing: ' . $quarantineRelative);
        }

        $this->ensureDirectory(dirname($target));

        // These exact bytes are already published (the hash is the name), so the
        // quarantined copy is redundant.
        if (is_file($target)) {
            @unlink($source);

            return $relative;
        }

        // A hard link leaves the quarantine name pointing at the same bytes, so
        // it has to be removed afterwards. A rename has already moved them.
        if (@link($source, $target)) {
            @unlink($source);

            return $relative;
        }

        if (@rename($source, $target)) {
            return $relative;
        }

        throw new \RuntimeException('Could not publish the file.');
    }

    /**
     * Copies a file the application produced (a generated cover, a backup) into
     * the store. Unlike quarantine() the source is ours, not an upload.
     */
    public function put(string $relative, string $sourcePath): void
    {
        $target = $this->absolute($relative);

        $this->ensureDirectory(dirname($target));

        if (!copy($sourcePath, $target)) {
            throw new \RuntimeException('Could not store ' . $relative);
        }

        chmod($target, 0640);
    }

    public function delete(string $relative): bool
    {
        $path = $this->absolute($relative);

        return is_file($path) ? @unlink($path) : false;
    }

    public function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new \RuntimeException('Could not hash the file.');
        }

        return $hash;
    }

    /** Total bytes under a subdirectory, for the storage dashboard. */
    public function usage(string $subdirectory): int
    {
        $base = $this->absolute($subdirectory);

        if (!is_dir($base)) {
            return 0;
        }

        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create ' . $directory);
        }
    }
}
