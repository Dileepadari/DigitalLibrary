<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\VerifyCsrf;
use PHPUnit\Framework\TestCase;

final class VerifyCsrfTest extends TestCase
{
    private Csrf $csrf;

    private VerifyCsrf $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $session = new Session(new Config(BASE_PATH . '/config'));
        $session->start();
        $this->csrf = new Csrf($session);
        $this->middleware = new VerifyCsrf($this->csrf);
    }

    private function next(): \Closure
    {
        return static fn (Request $request): Response => Response::html('passed');
    }

    public function testGetRequestsSkipTheCheck(): void
    {
        $response = $this->middleware->handle(Request::create('GET', '/'), $this->next());

        $this->assertSame('passed', $response->body());
    }

    public function testPostWithTheRightTokenPasses(): void
    {
        $request = Request::create('POST', '/requests', ['_token' => $this->csrf->token()]);

        $this->assertSame('passed', $this->middleware->handle($request, $this->next())->body());
    }

    public function testPostWithoutATokenIsRejected(): void
    {
        $this->csrf->token();

        try {
            $this->middleware->handle(Request::create('POST', '/requests'), $this->next());
            $this->fail('Expected the request to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->status());
        }
    }

    public function testPostWithTheWrongTokenIsRejected(): void
    {
        $this->expectException(HttpException::class);

        $this->middleware->handle(
            Request::create('POST', '/requests', ['_token' => 'forged']),
            $this->next()
        );
    }
}
