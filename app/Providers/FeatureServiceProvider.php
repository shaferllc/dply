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
 * Resolution model is config-first:
 * - config/features.php is the single global default for every scope.
 * - The features table holds only explicit per-org overrides, which Pennant
 *   applies before the resolver runs (an org row beats the config default).
 * - There is no null-scope "platform default" layer; admins change the global
 *   default via config/env, not by writing DB rows.
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

                // Resolve from config at check time so the global default always
                // reflects current config/env (and stays overridable in tests).
                Feature::define($name, fn () => (bool) config("features.{$namespace}.{$leaf}", false));
            }
        }
    }
}
