<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A counter per caller per minute, kept in files under storage/cache.
 *
 * No table, because a rate limit that writes to the database on every request
 * costs more than the requests it is protecting. Files in a tmpfs-backed cache
 * directory are cheap, and losing the counters on a restart is harmless.
 */
final class RateLimiter
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Counts this hit and says whether the caller is over the limit.
     *
     * @return array{allowed: bool, remaining: int, resets_in: int}
     */
    public function hit(string $key, int $limit, int $windowSeconds = 60): array
    {
        $window = (int) floor(time() / $windowSeconds);
        $path = $this->directory . '/rate-' . hash('sha256', $key . ':' . $window) . '.count';

        $this->prune();

        $count = 0;
        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            // If the counter cannot be kept, let the request through rather
            // than failing closed on a disk problem.
            return ['allowed' => true, 'remaining' => $limit, 'resets_in' => $windowSeconds];
        }

        if (flock($handle, LOCK_EX)) {
            $count = (int) stream_get_contents($handle);
            $count++;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $count);
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return [
            'allowed'   => $count <= $limit,
            'remaining' => max(0, $limit - $count),
            'resets_in' => ($window + 1) * $windowSeconds - time(),
        ];
    }

    /** Clears counters from windows that have passed. Cheap, and rarely runs. */
    private function prune(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        foreach (glob($this->directory . '/rate-*.count') ?: [] as $file) {
            if (filemtime($file) < time() - 300) {
                @unlink($file);
            }
        }
    }
}
