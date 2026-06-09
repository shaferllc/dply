<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        Horizon::auth(function ($request) {
            $user = $request->user();
            if ($user === null) {
                return false;
            }

            return Gate::forUser($user)->allows('viewHorizon') || app()->environment('local');
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if ($user === null) {
                return false;
            }

            // Horizon's own allow list PLUS platform admins — so an admin is
            // never locked out of the Horizon dashboard just because
            // HORIZON_ALLOWED_EMAILS is unset. Setting PLATFORM_ADMIN_EMAILS is
            // enough to grant both /admin and Horizon.
            $list = static fn (string $key): array => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) config($key, '')),
            )));
            $allowed = array_merge($list('horizon.allowed_emails'), $list('admin.allowed_emails'));

            return $allowed !== [] && in_array($user->email, $allowed, true);
        });
    }
}
