<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain PHP templates. A template opts into a layout with $this->layout(...)
 * and fills named slots with $this->section(...) / $this->end(); the layout
 * reads them back with $this->slot(...). Output is escaped by $this->e().
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var array<string, string> */
    private array $sections = [];

    private ?string $layout = null;

    private ?string $capturing = null;

    public function __construct(
        private readonly string $directory,
        private readonly Router $router,
        private readonly Config $config,
        private readonly ?Session $session = null,
    ) {
    }

    /** Flashed value from the previous request, for banners. */
    public function flash(string $key, mixed $default = null): mixed
    {
        return $this->session?->getFlash($key, $default) ?? $default;
    }

    /**
     * What the user typed into a form that failed validation.
     */
    public function old(string $key, string $default = ''): string
    {
        $old = $this->flash('old', []);
        $value = is_array($old) ? ($old[$key] ?? $default) : $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return list<string> */
    public function errors(string $field): array
    {
        $errors = $this->flash('errors', []);

        if (!is_array($errors) || !isset($errors[$field]) || !is_array($errors[$field])) {
            return [];
        }

        /** @var list<string> $messages */
        $messages = array_values($errors[$field]);

        return $messages;
    }

    public function hasError(string $field): bool
    {
        return $this->errors($field) !== [];
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Shared values are reachable from a template both as a local variable and
     * as a property: $auth and $this->auth are the same object. The property
     * form reads better in markup and cannot be shadowed by page data.
     */
    public function __get(string $name): mixed
    {
        return $this->shared[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->shared[$name]);
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $this->layout = null;
        $this->sections = [];

        $content = $this->capture($template, $data);

        if ($this->layout === null) {
            return $content;
        }

        $layout = $this->layout;
        $this->layout = null;
        $this->sections['content'] = $content;

        return $this->capture($layout, $data);
    }

    /** @param array<string, mixed> $data */
    public function include(string $template, array $data = []): void
    {
        echo $this->capture($template, $data);
    }

    public function layout(string $template): void
    {
        $this->layout = $template;
    }

    public function section(string $name): void
    {
        $this->capturing = $name;
        ob_start();
    }

    public function end(): void
    {
        if ($this->capturing === null) {
            throw new \LogicException('end() called without a matching section().');
        }

        $this->sections[$this->capturing] = (string) ob_get_clean();
        $this->capturing = null;
    }

    public function slot(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSlot(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, string|int> $parameters */
    public function url(string $name, array $parameters = []): string
    {
        return $this->router->url($name, $parameters);
    }

    /** Cache-busted asset path. */
    public function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $file = BASE_PATH . '/public' . $path;

        return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get($key, $default);
    }

    /**
     * Templates call back into layout() and section() while they run, so this
     * mutates the view even though it looks like a pure string builder.
     *
     * @param array<string, mixed> $data
     *
     * @phpstan-impure
     */
    private function capture(string $template, array $data): string
    {
        $path = $this->directory . '/' . trim($template, '/') . '.php';

        if (!is_file($path)) {
            throw new \RuntimeException("View [{$template}] not found at {$path}.");
        }

        $level = ob_get_level();
        ob_start();

        try {
            // The closure's own variables are prefixed because extract() runs
            // with EXTR_SKIP: a page variable that collided with one of them
            // would be silently dropped rather than overwriting it, and the
            // template would quietly render with the wrong value. Page data must
            // not use the names __path and __data.
            (function () use ($path, $data): void {
                $__path = $path;
                $__data = $data;
                unset($path, $data);

                extract(array_merge($this->shared, $__data), EXTR_SKIP);

                require $__path;
            })();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
