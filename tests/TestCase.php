<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Csrf;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // $_SESSION stands in for the cookie: it survives between the requests of
        // one test and is cleared between tests.
        $_SESSION = [];
    }

    /**
     * A freshly booted application, the same way public/index.php boots it. Each
     * request gets its own, so nothing cached in a service leaks across them.
     */
    protected function kernel(): Kernel
    {
        return require BASE_PATH . '/bootstrap.php';
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     */
    protected function request(string $method, string $path, array $body = [], array $files = []): Response
    {
        return $this->kernel()->handle(Request::create($method, $path, $body, [], $files));
    }

    protected function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    /**
     * Posts with a valid CSRF token, because every browser route requires one.
     *
     * @param array<string, mixed> $body
     */
    protected function post(string $path, array $body = []): Response
    {
        return $this->request('POST', $path, array_merge(['_token' => $this->csrfToken()], $body));
    }

    /**
     * A POST carrying a file, the way a multipart form arrives.
     *
     * @param array<string, mixed> $files
     * @param array<string, mixed> $body
     */
    protected function upload(string $path, array $files, array $body = []): Response
    {
        return $this->request('POST', $path, array_merge(['_token' => $this->csrfToken()], $body), $files);
    }

    protected function csrfToken(): string
    {
        return $this->kernel()->container()->get(Csrf::class)->token();
    }

    /**
     * The bytes a response would send. A file download builds its body from a
     * stream callback, so reading ->body() would give an empty string.
     */
    protected function bodyOf(Response $response): string
    {
        if (!$response->hasStream()) {
            return $response->body();
        }

        // The streaming body calls ob_flush() as it goes, which would empty a
        // plain ob_start() buffer before ob_get_clean() could read it. An output
        // handler sees every chunk instead, and returns '' so nothing reaches
        // the test runner's own output.
        $captured = '';

        ob_start(static function (string $chunk) use (&$captured): string {
            $captured .= $chunk;

            return '';
        }, 1);

        $response->send();
        ob_end_flush();

        return $captured;
    }

    /**
     * HTML with its whitespace collapsed, for asserting on a sentence that the
     * template happens to have broken across lines and indentation.
     */
    protected function flatten(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }

    /**
     * The readable text of a page: tags removed and whitespace collapsed, for
     * asserting on a sentence that has a link in the middle of it.
     */
    protected function text(string $html): string
    {
        return $this->flatten(strip_tags($html));
    }

    protected function assertRedirectedTo(string $expected, Response $response): void
    {
        $this->assertContains($response->status(), [302, 303], 'Expected a redirect.');
        $this->assertSame($expected, $response->headers()['Location'] ?? null);
    }
}
