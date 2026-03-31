<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsAuthorized;
use App\Models\User;

class TestAuthorizedWithArgumentsAction extends Actions
{
    use AsAuthorized;

    public string $commandSignature = 'test:authorized-with-arguments-action';

    public function handle(User $user): string
    {
        return 'authorized';
    }

    public function getAuthorizationAbility(): string
    {
        return 'view-user';
    }

    public function getAuthorizationArguments(User $user): array
    {
        return [$user];
    }
}
