<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Runs middleware around a destination, outermost first.
 *
 * A middleware is named by class, optionally followed by a colon and a comma
 * separated argument list: 'App\Middleware\Authorize:user.manage'.
 */
final class Pipeline
{
    public function __construct(private readonly Container $container)
    {
    }

    /** @param list<string> $middleware */
    public function run(Request $request, array $middleware, Closure $destination): Response
    {
        $next = $destination;

        foreach (array_reverse($middleware) as $declaration) {
            $current = $next;
            $next = function (Request $request) use ($declaration, $current): Response {
                [$class, $arguments] = self::parse($declaration);
                $instance = $this->container->get($class);

                if (!$instance instanceof Middleware) {
                    throw new \RuntimeException("[{$class}] is not a Middleware.");
                }

                return $instance->handle($request, $current, ...$arguments);
            };
        }

        return $next($request);
    }

    /** @return array{0: string, 1: list<string>} */
    public static function parse(string $declaration): array
    {
        if (!str_contains($declaration, ':')) {
            return [$declaration, []];
        }

        [$class, $arguments] = explode(':', $declaration, 2);

        return [$class, array_values(array_filter(array_map('trim', explode(',', $arguments))))];
    }
}
