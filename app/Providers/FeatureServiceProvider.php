<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * Registers every flag declared in config/features.php with Pennant.
 *
 * Naming convention is dot-namespaced: "{namespace}.{leaf}" where the two
 * levels come straight from the config keys.
 *
 * Resolution model is hybrid: the closure returns the config default;
 * Pennant's database store caches that value per-scope on first read; the
 * `feature:set` artisan command writes per-scope overrides directly into
 * that store. When flipping a default broadly, run `php artisan pennant:purge`
 * to drop cached rows so the new default takes effect for orgs that
 * never explicitly overrode.
 *
 * Default scope: `auth()->user()?->currentOrganization()`. Flags named
 * `global.*` are app-wide and should be checked with `Feature::for(null)`
 * (the @feature directive accepts the same name).
 */
class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Feature::resolveScopeUsing(fn () => auth()->user()?->currentOrganization());

        foreach (config('features', []) as $namespace => $flags) {
            foreach ($flags as $leaf => $default) {
                Feature::define("$namespace.$leaf", fn () => (bool) $default);
            }
        }
    }
}
