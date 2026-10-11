<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves middleware names like "auth", "guest", "permission:products.view"
 * into Middleware instances.
 */
final class MiddlewareResolver
{
    /** @var array<string,\Closure(): Middleware> */
    private array $bindings = [];

    public function register(string $name, \Closure $factory): void
    {
        $this->bindings[$name] = $factory;
    }

    public function resolve(string $name): Middleware
    {
        [$base, $arg] = array_pad(explode(':', $name, 2), 2, null);
        $factory = $this->bindings[$base]
            ?? throw new \RuntimeException("Unknown middleware [{$base}].");
        /** @var Middleware $middleware */
        $middleware = $factory();
        if ($arg !== null) {
            $middleware->setArgument($arg);
        }
        return $middleware;
    }
}
