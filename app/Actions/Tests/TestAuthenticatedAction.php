<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsAuthenticated;

class TestAuthenticatedAction extends Actions
{
    use AsAuthenticated;

    public string $commandSignature = 'test:authenticated-action';

    public function handle(): string
    {
        return 'authenticated';
    }
}
