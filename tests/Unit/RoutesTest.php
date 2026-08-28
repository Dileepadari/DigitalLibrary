<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Router;
use Tests\TestCase;

/**
 * `Foo::class` in a file with no `use Foo;` silently evaluates to the string
 * "Foo", and nothing notices until someone requests that route and gets a 500.
 * These tests are the thing that notices.
 */
final class RoutesTest extends TestCase
{
    private function router(): Router
    {
        return $this->kernel()->container()->get(Router::class);
    }

    public function testEveryRouteHandlerExists(): void
    {
        foreach ($this->router()->routes() as $route) {
            $handler = $route->handler();

            if (!is_array($handler)) {
                continue;
            }

            [$class, $method] = $handler;

            $this->assertTrue(class_exists($class), "Route {$route->uri()} points at a missing class [{$class}].");
            $this->assertTrue(
                method_exists($class, $method),
                "Route {$route->uri()} points at a missing method [{$class}::{$method}]."
            );
        }
    }

    public function testEveryRouteMiddlewareExists(): void
    {
        foreach ($this->router()->routes() as $route) {
            foreach ($route->middlewares() as $declaration) {
                [$class] = \App\Core\Pipeline::parse($declaration);

                $this->assertTrue(
                    class_exists($class),
                    "Route {$route->uri()} names a missing middleware [{$class}]."
                );
            }
        }
    }

    public function testEveryNamedRouteCanBuildItsUrl(): void
    {
        $router = $this->router();

        foreach ($router->routes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            preg_match_all(\App\Core\Route::PLACEHOLDER, $route->uri(), $matches);
            $parameters = array_fill_keys($matches[1], '1');

            $this->assertIsString($router->url($name, $parameters));
        }
    }

    public function testRouteNamesAreUnique(): void
    {
        $names = [];

        foreach ($this->router()->routes() as $route) {
            $name = $route->getName();

            if ($name !== null) {
                $this->assertNotContains($name, $names, "Two routes are named [{$name}].");
                $names[] = $name;
            }
        }
    }
}
