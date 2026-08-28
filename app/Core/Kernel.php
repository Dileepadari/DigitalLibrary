<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Turns a Request into a Response: match a route, run the middleware stack,
 * call the controller, and convert anything thrown into an error page.
 */
final class Kernel
{
    /** @param list<string> $globalMiddleware */
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Config $config,
        private readonly array $globalMiddleware = [],
    ) {
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        try {
            [$route, $parameters] = $this->router->match($request);
            $request = $request->withParameters($parameters);

            return (new Pipeline($this->container))->run(
                $request,
                array_merge($this->globalMiddleware, $route->middlewares()),
                fn (Request $request): Response => $this->call($route->handler(), $request)
            );
        } catch (HttpException $e) {
            return $this->renderError($request, $e->status(), $e->getMessage(), null);
        } catch (\Throwable $e) {
            $this->container->get(Logger::class)->exception($e, $request->path());

            return $this->renderError($request, 500, 'Something went wrong.', $e);
        }
    }

    private function call(mixed $handler, Request $request): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            $result = $controller->{$method}($request);
        } else {
            $result = $handler($request);
        }

        if (!$result instanceof Response) {
            throw new \RuntimeException('A route handler must return a Response.');
        }

        return $result;
    }

    private function renderError(Request $request, int $status, string $message, ?\Throwable $e): Response
    {
        $debug = (bool) $this->config->get('app.debug', false);

        if ($request->wantsJson()) {
            $payload = ['error' => $message, 'status' => $status];

            if ($debug && $e !== null) {
                $payload['exception'] = $e::class;
                $payload['file'] = $e->getFile() . ':' . $e->getLine();
            }

            return Response::json($payload, $status);
        }

        try {
            $template = $status === 404 ? 'errors/404' : 'errors/error';

            return Response::html($this->container->get(View::class)->render($template, [
                'status'    => $status,
                'message'   => $message,
                'exception' => $debug ? $e : null,
            ]), $status);
        } catch (\Throwable) {
            // The error page itself failed. Fall back to something that cannot.
            return Response::html(
                '<h1>' . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>',
                $status
            );
        }
    }
}
