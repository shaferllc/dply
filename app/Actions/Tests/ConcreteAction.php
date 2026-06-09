<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;

/**
 * Concrete implementation of Actions for testing purposes.
 */
class ConcreteAction extends Actions
{
    public function getControllerMiddleware(): array
    {
        return ['auth:sanctum', 'api', 'verified'];
    }

    public function handle(): void
    {
        // Empty implementation for testing
    }
}
