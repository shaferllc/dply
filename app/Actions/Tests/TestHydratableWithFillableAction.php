<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsHydratable;

class TestHydratableWithFillableAction extends Actions
{
    use AsHydratable;

    public string $commandSignature = 'test:hydratable-fillable-action';

    public string $name = '';

    public string $email = '';

    public string $internal = '';

    protected function getFillable(): array
    {
        return ['name', 'email']; // Exclude 'internal'
    }
}
