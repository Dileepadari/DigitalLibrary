<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use App\Core\Pipeline;
use App\Core\Request;
use App\Core\Response;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\BlockingMiddleware;
use Tests\Doubles\RecordingMiddleware;

final class PipelineTest extends TestCase
{
    public function testParsesAMiddlewareWithoutArguments(): void
    {
        $this->assertSame(['App\\Middleware\\VerifyCsrf', []], Pipeline::parse('App\\Middleware\\VerifyCsrf'));
    }

    public function testParsesArgumentsAfterTheColon(): void
    {
        [$class, $arguments] = Pipeline::parse('App\\Middleware\\Authorize:user.manage, settings.manage');

        $this->assertSame('App\\Middleware\\Authorize', $class);
        $this->assertSame(['user.manage', 'settings.manage'], $arguments);
    }

    public function testRunsMiddlewareOutermostFirstAndPassesArguments(): void
    {
        $container = new Container();
        RecordingMiddleware::$seen = [];
        $container->instance(RecordingMiddleware::class, new RecordingMiddleware());

        $response = (new Pipeline($container))->run(
            Request::create('GET', '/'),
            [RecordingMiddleware::class . ':one', RecordingMiddleware::class . ':two'],
            static fn (): Response => Response::html('destination')
        );

        $this->assertSame('destination', $response->body());
        $this->assertSame(['one', 'two'], RecordingMiddleware::$seen);
    }

    public function testShortCircuitingSkipsTheDestination(): void
    {
        $container = new Container();
        $container->instance(BlockingMiddleware::class, new BlockingMiddleware());

        $response = (new Pipeline($container))->run(
            Request::create('GET', '/'),
            [BlockingMiddleware::class],
            static fn (): Response => Response::html('should not run')
        );

        $this->assertSame('blocked', $response->body());
    }

    public function testRefusesSomethingThatIsNotMiddleware(): void
    {
        $container = new Container();
        $container->instance('NotMiddleware', new \stdClass());

        $this->expectException(\RuntimeException::class);

        (new Pipeline($container))->run(
            Request::create('GET', '/'),
            ['NotMiddleware'],
            static fn (): Response => Response::html('x')
        );
    }
}
