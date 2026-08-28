<?php

declare(strict_types=1);

namespace App\Core;

final class Route
{
    /** Matches {name}, {name...} and {name:constraint}. Shared with Router::url(). */
    public const PLACEHOLDER = '/\{([a-zA-Z_][a-zA-Z0-9_]*)(\.\.\.)?(?::([^}]+))?\}/';

    private ?string $name = null;

    /** @var list<string> class name, optionally with ':arguments' appended */
    private array $middleware = [];

    /**
     * @param list<string>                      $methods
     * @param callable|array{class-string,string} $handler
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $uri,
        private readonly mixed $handler,
    ) {
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @param string ...$middleware class name, optionally with ':arguments' appended */
    public function middleware(string ...$middleware): self
    {
        foreach ($middleware as $item) {
            if (!in_array($item, $this->middleware, true)) {
                $this->middleware[] = $item;
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function handlerName(): string
    {
        if (is_array($this->handler)) {
            return $this->handler[0] . '@' . $this->handler[1];
        }

        return 'Closure';
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function middlewares(): array
    {
        return $this->middleware;
    }

    /**
     * Turn /books/{slug} into a named-group regex. {name} matches one segment,
     * {name...} matches the rest of the path (used by the category and
     * collection trees), and {name:pattern} takes an explicit constraint.
     *
     * The literal parts are quoted one at a time rather than quoting the whole
     * URI first, because preg_quote escapes the braces the placeholders are
     * written with.
     */
    public function pattern(): string
    {
        preg_match_all(
            self::PLACEHOLDER,
            $this->uri,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        $pattern = '';
        $offset = 0;

        foreach ($matches as $match) {
            [$placeholder, $position] = $match[0];

            $pattern .= preg_quote(substr($this->uri, $offset, $position - $offset), '#');

            $name = (string) $match[1][0];
            $explicit = (string) ($match[3][0] ?? '');
            $greedy = (string) ($match[2][0] ?? '') === '...';

            $constraint = $explicit !== '' ? $explicit : ($greedy ? '.+' : '[^/]+');
            $pattern .= '(?P<' . $name . '>' . $constraint . ')';

            $offset = $position + strlen((string) $placeholder);
        }

        $pattern .= preg_quote(substr($this->uri, $offset), '#');

        return '#^' . $pattern . '$#';
    }
}
