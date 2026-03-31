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

            $raw = (string) config('horizon.allowed_emails', '');
            $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));

            if ($allowed === []) {
                return false;
            }

            return in_array($user->email, $allowed, true);
        });
    }
}
