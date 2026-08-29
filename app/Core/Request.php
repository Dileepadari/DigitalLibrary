<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, string> route parameters, filled by the router */
    private array $parameters = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $files
     * @param array<string, string> $headers
     * @param array<string, mixed>  $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $files = [],
        private readonly array $headers = [],
        private readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Browsers only send GET and POST. A _method field on a POST form lets a
        // route be declared PUT/PATCH/DELETE without JavaScript.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);

            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $body = $_POST;

        if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self(
            $method,
            is_string($path) ? $path : '/',
            $_GET,
            $body,
            $_FILES,
            self::headersFromServer($_SERVER),
            $_SERVER,
        );
    }

    /**
     * Build a request by hand. Used by tests and by the CLI.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, mixed> $files $_FILES-shaped entries
     */
    public static function create(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        array $files = [],
    ): self {
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $parsed);
            $query = array_merge($parsed, $query);
        }

        return new self(strtoupper($method), $path, $query, $body, $files);
    }

    public function method(): string
    {
        return $this->method;
    }

    /** Path with the trailing slash trimmed, always starting with a slash. */
    public function path(): string
    {
        $path = '/' . trim($this->path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> the query string as it arrived */
    public function queryParameters(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** @return array<string, mixed>|null a $_FILES entry */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function userAgent(): string
    {
        return $this->header('user-agent', '') ?? '';
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
            || ($this->header('x-forwarded-proto') === 'https');
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path(), '/api/')
            || str_contains((string) $this->header('accept', ''), 'application/json');
    }

    public function parameter(string $key, ?string $default = null): ?string
    {
        return $this->parameters[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * A copy carrying these headers. Used by the tests to send a Range header
     * without going through a web server.
     *
     * @param array<string, string> $headers lowercase names
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->files,
            array_merge($this->headers, $headers),
            $this->server,
        );
    }

    /** @param array<string, string> $parameters */
    public function withParameters(array $parameters): self
    {
        $clone = clone $this;
        $clone->parameters = $parameters;

        return $clone;
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }

        return $headers;
    }
}
