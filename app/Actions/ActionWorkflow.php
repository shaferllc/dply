<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Action Workflow - Executable workflow.
 */
class ActionWorkflow
{
    public function __construct(
        protected string $name,
        protected array $steps,
        protected bool $stopOnFailure = true
    ) {}

    /**
     * Execute the workflow.
     *
     * @param  mixed  ...$arguments  Initial arguments
     * @return mixed Final result
     */
    public function execute(...$arguments): mixed
    {
        $value = $arguments[0] ?? null;
        $otherArgs = array_slice($arguments, 1);

        foreach ($this->steps as $step) {
            try {
                if (isset($step['type']) && $step['type'] === 'conditional') {
                    if (! $step['condition']($value, ...$otherArgs)) {
                        continue;
                    }
                    $action = $step['action'];
                } else {
                    $action = $step['action'];
                }

                if (is_callable($action)) {
                    $value = $action($value, ...$otherArgs, ...($step['args'] ?? []));
                } elseif (is_string($action) && class_exists($action)) {
                    $instance = app($action);
                    $value = $instance->handle($value, ...$otherArgs, ...($step['args'] ?? []));
                }
            } catch (\Throwable $e) {
                if ($this->stopOnFailure) {
                    throw $e;
                }
                // Continue with previous value
            }
        }

        return $value;
    }

    /**
     * Get workflow name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get workflow steps.
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Check if workflow stops on failure.
     */
    public function shouldStopOnFailure(): bool
    {
        return $this->stopOnFailure;
    }
}
