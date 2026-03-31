<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Models\User;

class TestUserWithPermissions extends User
{
    protected $table = 'users';

    public function getAllPermissions()
    {
        return collect($this->permissions ?? []);
    }

    public function getPermissionNames()
    {
        return collect($this->permissions ?? []);
    }
}
