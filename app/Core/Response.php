<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @var (\Closure(): void)|null set for a file download: see withStream() */
    private ?\Closure $stream = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Produce the body by echoing from a callback instead of holding it in a
     * string. Used by the file controller so a large scan streams rather than
     * being read into memory.
     *
     * @param \Closure(): void $stream
     */
    public function withStream(\Closure $stream): self
    {
        $this->stream = $stream;

        return $this;
    }

    public function hasStream(): bool
    {
        return $this->stream !== null;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->stream !== null) {
            ($this->stream)();

            return;
        }

        echo $this->body;
    }
}
