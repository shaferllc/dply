<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsPermission;

class TestPermissionAction extends Actions
{
    use AsPermission;

    public function handle(): string
    {
        return 'authorized';
    }

    protected function getRequiredPermissions(): array
    {
        return ['users.view'];
    }
}
