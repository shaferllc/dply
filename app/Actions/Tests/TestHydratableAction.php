<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsHydratable;

class TestHydratableAction extends Actions
{
    use AsHydratable;

    public string $commandSignature = 'test:hydratable-action';

    public string $name = '';

    public string $email = '';

    public ?string $phone = null;
}
