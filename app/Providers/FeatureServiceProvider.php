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
 * Resolution model is hybrid:
 * - global.* → config/env default; optional Pennant null-scope override.
 * - org-scoped flags → org override → platform default (Pennant null scope)
 *   → config/env default.
 *
 * Default scope: auth()->user()?->currentOrganization(). global.* checks
 * should use Feature::for(null) when a true app-wide value is required.
 */
class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Feature::resolveScopeUsing(fn () => auth()->user()?->currentOrganization());

        foreach (config('features', []) as $namespace => $flags) {
            foreach ($flags as $leaf => $default) {
                $name = "$namespace.$leaf";
                $configDefault = (bool) $default;

                Feature::define($name, function ($scope) use ($name, $namespace, $configDefault) {
                    if ($namespace === 'global' || $scope === null) {
                        return $configDefault;
                    }

                    return (bool) Feature::for(null)->value($name);
                });
            }
        }
    }
}
