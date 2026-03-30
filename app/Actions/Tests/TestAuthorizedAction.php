<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsAuthorized;

class TestAuthorizedAction extends Actions
{
    use AsAuthorized;

    public string $commandSignature = 'test:authorized-action';

    public function handle(): string
    {
        return 'authorized';
    }

    public function getAuthorizationAbility(): string
    {
        return 'view-reports';
    }
}
