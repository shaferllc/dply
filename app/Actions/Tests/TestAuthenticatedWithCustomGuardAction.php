<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsAuthenticated;

class TestAuthenticatedWithCustomGuardAction extends Actions
{
    use AsAuthenticated;

    public string $commandSignature = 'test:authenticated-custom-guard-action';

    public string $authGuard = 'api';

    public function handle(): string
    {
        return 'authenticated';
    }

    protected function getAuthGuard(): string
    {
        return 'api';
    }
}
