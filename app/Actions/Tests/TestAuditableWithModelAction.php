<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsAuditable;
use App\Models\User;

class TestAuditableWithModelAction extends Actions
{
    use AsAuditable;

    public function handle(User $user): User
    {
        $user->update(['name' => 'Updated']);
        $user->refresh();

        return $user;
    }
}
