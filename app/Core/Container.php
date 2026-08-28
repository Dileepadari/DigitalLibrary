<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Small service container: explicit bindings first, constructor autowiring as a
 * fallback so controllers can type-hint what they need.
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $id, Closure $factory): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id] = false;
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id] = true;
    }

    public function instance(string $id, mixed $object): void
    {
        $this->instances[$id] = $object;
    }

    /**
     * Autowire this class once and reuse it. The common case for a service that
     * caches something for the length of a request.
     *
     * @param class-string $class
     */
    public function share(string $class): void
    {
        $this->singleton($class, static fn (Container $container): object => $container->autowire($class));
    }

    /** @param class-string $class */
    public function autowire(string $class): object
    {
        return $this->build($class);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $object = ($this->bindings[$id])($this);

            if ($this->shared[$id]) {
                $this->instances[$id] = $object;
            }

            return $object;
        }

        return $this->build($id);
    }

    /** @param class-string $class */
    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Cannot resolve [{$class}] from the container.");
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            throw new \RuntimeException(
                "Cannot resolve parameter \${$parameter->getName()} of [{$class}]."
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
