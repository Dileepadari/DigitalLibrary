<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $handler = static fn (): Response => Response::html('ok');

        $router->get('/', $handler)->name('home');
        $router->get('/books/{slug}', $handler)->name('books.show');
        $router->get('/categories/{path...}', $handler)->name('categories.show');
        $router->get('/requests/{id:[0-9]+}', $handler)->name('requests.show');
        $router->post('/requests', $handler)->name('requests.store');

        return $router;
    }

    public function testMatchesAStaticPath(): void
    {
        [$route] = $this->router()->match(Request::create('GET', '/'));

        $this->assertSame('home', $route->getName());
    }

    public function testCapturesASingleSegmentParameter(): void
    {
        [$route, $parameters] = $this->router()->match(Request::create('GET', '/books/pride-and-prejudice'));

        $this->assertSame('books.show', $route->getName());
        $this->assertSame(['slug' => 'pride-and-prejudice'], $parameters);
    }

    public function testWildcardParameterCapturesTheRestOfThePath(): void
    {
        [$route, $parameters] = $this->router()->match(
            Request::create('GET', '/categories/academics/competitive-exams/upsc')
        );

        $this->assertSame('categories.show', $route->getName());
        $this->assertSame('academics/competitive-exams/upsc', $parameters['path']);
    }

    public function testConstraintIsEnforced(): void
    {
        [$route, $parameters] = $this->router()->match(Request::create('GET', '/requests/42'));
        $this->assertSame(['id' => '42'], $parameters);

        $this->expectException(HttpException::class);
        $this->router()->match(Request::create('GET', '/requests/abc'));
    }

    public function testUnknownPathIsNotFound(): void
    {
        try {
            $this->router()->match(Request::create('GET', '/nope'));
            $this->fail('Expected a 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status());
        }
    }

    public function testKnownPathWithWrongMethodIsMethodNotAllowed(): void
    {
        try {
            $this->router()->match(Request::create('DELETE', '/requests'));
            $this->fail('Expected a 405.');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
            $this->assertStringContainsString('POST', $e->getMessage());
        }
    }

    public function testTrailingSlashesAreIgnored(): void
    {
        [$route] = $this->router()->match(Request::create('GET', '/books/dune/'));

        $this->assertSame('books.show', $route->getName());
    }

    public function testGeneratesUrlsFromNames(): void
    {
        $this->assertSame('/books/dune', $this->router()->url('books.show', ['slug' => 'dune']));
    }

    public function testGeneratingAUrlWithoutItsParameterFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->router()->url('books.show');
    }

    public function testGroupAppliesPrefixAndMiddleware(): void
    {
        $router = new Router();
        $router->group(
            ['prefix' => '/api/v1', 'middleware' => ['Middleware\\Fake']],
            static function (Router $router): void {
                $router->get('/books', static fn (): Response => Response::json([]))->name('api.books');
            }
        );

        [$route] = $router->match(Request::create('GET', '/api/v1/books'));

        $this->assertSame('/api/v1/books', $route->uri());
        $this->assertSame(['Middleware\\Fake'], $route->middlewares());
    }
}
