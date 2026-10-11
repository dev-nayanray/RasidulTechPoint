<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Base middleware contract: wraps the request pipeline.
 */
abstract class Middleware
{
    protected ?string $argument = null;

    public function setArgument(string $argument): void
    {
        $this->argument = $argument;
    }

    /**
     * @param callable(Request): Response $next
     */
    abstract public function handle(Request $request, callable $next): Response;
}
