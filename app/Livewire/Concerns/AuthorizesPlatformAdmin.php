<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Gate;

/**
 * Platform-admin gate for any Livewire component that renders admin-only data.
 *
 * Lives in the generic concerns namespace, not App\\Livewire\\Admin\\Concerns:
 * it is a Gate call with no coupling to the admin shell, and module components
 * legitimately need it (the Feedback module's admin review screen was the
 * boundary-test exemption this move pays off).
 */
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
