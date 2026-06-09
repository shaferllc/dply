<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Concerns;

use Illuminate\Support\Facades\Gate;

trait AuthorizesPlatformAdmin
{
    public function mountAuthorizesPlatformAdmin(): void
    {
        Gate::authorize('viewPlatformAdmin');
    }

    protected function authorizePlatformAdmin(): void
    {
        Gate::authorize('viewPlatformAdmin');
    }
}
