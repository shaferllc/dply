<?php

namespace App\Providers;

use App\Support\Admin\PlatformFeatureDefaults;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * Registers every flag declared in config/features.php with Pennant.
 *
 * Naming convention is dot-namespaced: "{namespace}.{leaf}" where the two
 * levels come straight from the config keys.
 *
 * Resolution precedence (highest wins):
 * - An explicit per-org row in the `features` table (Pennant applies it
 *   before the resolver even runs).
 * - A platform override (feature_platform_overrides table), managed from
 *   /admin/flags/all — beats the config default for every scope.
 * - config/features.php (env) — the global default of last resort.
 *
 * Default scope: auth()->user()?->currentOrganization().
 */
class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Feature::resolveScopeUsing(fn () => auth()->user()?->currentOrganization());

        foreach (config('features', []) as $namespace => $flags) {
            // `beta_bundle` is a reserved list (the beta-invite override set),
            // not a flag namespace — see config/features.php.
            if ($namespace === 'beta_bundle') {
                continue;
            }

            foreach (array_keys($flags) as $leaf) {
                $name = "$namespace.$leaf";

                // Resolve at check time: a platform override (set from the admin
                // flags UI) beats config; otherwise fall back to the config/env
                // default so it stays overridable in tests.
                Feature::define($name, function () use ($name, $namespace, $leaf): bool {
                    $override = PlatformFeatureDefaults::get($name);
                    if ($override !== null) {
                        return $override;
                    }

                    return (bool) config("features.{$namespace}.{$leaf}", false);
                });
            }
        }
    }
}
