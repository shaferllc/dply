<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Api\BundleUserinfoController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * "Log in with dply" — the OIDC provider wiring for the bundled-products perk.
 *
 * Deliberately INERT until Laravel Passport is installed: every Passport
 * reference is behind `class_exists(Passport::class)`, so before
 * `composer require laravel/passport` this provider is a total no-op and cannot
 * fatal the app (critical — this is a prod-only environment). Once Passport is
 * present it sets token lifetimes, registers the `bundle` scope, and exposes the
 * OIDC userinfo endpoint under the Passport guard.
 *
 * Activation: `composer require laravel/passport` → `php artisan passport:install`
 * → create the tracely + Lookout clients (`php artisan passport:client` with the
 * redirect URIs in config('bundle.sso.clients')). See docs/adr/bundled-products-sso.md.
 */
final class BundleSsoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(\Laravel\Passport\Passport::class)) {
            return;
        }

        \Laravel\Passport\Passport::tokensExpireIn(
            now()->addMinutes((int) config('bundle.sso.access_token_ttl_minutes', 60)),
        );
        \Laravel\Passport\Passport::refreshTokensExpireIn(
            now()->addDays((int) config('bundle.sso.refresh_token_ttl_days', 30)),
        );
        \Laravel\Passport\Passport::tokensCan([
            'bundle' => 'Access bundled products (tracely + Lookout)',
        ]);

        // OIDC userinfo — only under the Passport access-token guard, so it can't
        // be reached until Passport is installed + a token issued.
        Route::middleware(['api', 'auth:api'])
            ->prefix('api/v1')
            ->group(function (): void {
                Route::get('/userinfo', [BundleUserinfoController::class, 'show'])
                    ->middleware('throttle:120,1');
            });
    }
}
