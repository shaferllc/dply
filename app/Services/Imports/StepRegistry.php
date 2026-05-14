<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Illuminate\Container\Container;
use RuntimeException;

/**
 * Maps ImportMigrationStep::KEY_* strings to concrete StepHandler classes.
 * The orchestrator asks the registry for a handler by key; the registry
 * resolves through the service container so handlers can depend-inject
 * services (driver factories, SSH connections, etc.) without the
 * orchestrator knowing the shape.
 */
class StepRegistry
{
    /**
     * @param  array<string, class-string<StepHandler>>  $bindings
     */
    public function __construct(protected array $bindings = []) {}

    /**
     * @param  class-string<StepHandler>  $handlerClass
     */
    public function register(string $stepKey, string $handlerClass): void
    {
        $this->bindings[$stepKey] = $handlerClass;
    }

    public function has(string $stepKey): bool
    {
        return isset($this->bindings[$stepKey]);
    }

    public function resolve(string $stepKey): StepHandler
    {
        if (! isset($this->bindings[$stepKey])) {
            throw new RuntimeException("No handler registered for step '{$stepKey}'.");
        }

        return Container::getInstance()->make($this->bindings[$stepKey]);
    }
}
