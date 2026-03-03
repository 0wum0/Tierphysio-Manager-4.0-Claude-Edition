<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use InvalidArgumentException;
use RuntimeException;

class Container
{
    private array $bindings = [];
    private array $singletons = [];
    private array $instances = [];

    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    public function singleton(string $abstract, Closure $factory): void
    {
        $this->singletons[$abstract] = $factory;
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function get(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = ($this->singletons[$abstract])($this);
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }

        return $this->resolve($abstract);
    }

    private function resolve(string $abstract): mixed
    {
        if (!class_exists($abstract)) {
            throw new RuntimeException("Cannot resolve [{$abstract}]: class not found and no binding registered.");
        }

        $reflection = new \ReflectionClass($abstract);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Class [{$abstract}] is not instantiable.");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $abstract();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
            } else {
                throw new RuntimeException(
                    "Cannot resolve parameter [{$param->getName()}] for [{$abstract}]."
                );
            }
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->bindings[$abstract])
            || class_exists($abstract);
    }
}
