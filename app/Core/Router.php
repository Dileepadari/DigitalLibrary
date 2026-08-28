<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    private string $prefix = '';

    /** @var list<string> */
    private array $groupMiddleware = [];

    public function get(string $uri, mixed $handler): Route
    {
        return $this->add(['GET', 'HEAD'], $uri, $handler);
    }

    public function post(string $uri, mixed $handler): Route
    {
        return $this->add(['POST'], $uri, $handler);
    }

    public function put(string $uri, mixed $handler): Route
    {
        return $this->add(['PUT'], $uri, $handler);
    }

    public function patch(string $uri, mixed $handler): Route
    {
        return $this->add(['PATCH'], $uri, $handler);
    }

    public function delete(string $uri, mixed $handler): Route
    {
        return $this->add(['DELETE'], $uri, $handler);
    }

    /**
     * @param array{prefix?: string, middleware?: list<string>} $options
     */
    public function group(array $options, callable $routes): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix = $previousPrefix . rtrim($options['prefix'] ?? '', '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $options['middleware'] ?? []);

        $routes($this);

        $this->prefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /** @param list<string> $methods */
    public function add(array $methods, string $uri, mixed $handler): Route
    {
        $uri = $this->prefix . '/' . trim($uri, '/');
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');

        $route = new Route($methods, $uri === '' ? '/' : $uri, $handler);
        $route->middleware(...$this->groupMiddleware);

        $this->routes[] = $route;

        return $route;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return array{0: Route, 1: array<string, string>}
     *
     * @throws HttpException 404 when nothing matches the path, 405 when the path
     *                       matches but the method does not
     */
    public function match(Request $request): array
    {
        $path = $request->path();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route->pattern(), $path, $matches) !== 1) {
                continue;
            }

            if (!in_array($request->method(), $route->methods(), true)) {
                $allowed = array_merge($allowed, $route->methods());

                continue;
            }

            $parameters = [];

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $parameters[$key] = $value;
                }
            }

            return [$route, $parameters];
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed(
                'Allowed: ' . implode(', ', array_unique($allowed))
            );
        }

        throw HttpException::notFound();
    }

    /** @param array<string, string|int> $parameters */
    public function url(string $name, array $parameters = []): string
    {
        foreach ($this->routes as $route) {
            if ($route->getName() !== $name) {
                continue;
            }

            $uri = preg_replace_callback(
                Route::PLACEHOLDER,
                static function (array $m) use ($name, $parameters): string {
                    if (!array_key_exists($m[1], $parameters)) {
                        throw new \InvalidArgumentException(
                            "Route [{$name}] needs a value for {{$m[1]}}."
                        );
                    }

                    return (string) $parameters[$m[1]];
                },
                $route->uri()
            );

            return (string) $uri;
        }

        throw new \InvalidArgumentException("No route named [{$name}].");
    }
}
