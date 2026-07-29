<?php

use Laravel\Pennant\Feature;
use Tests\Concerns\FakesRemoteServerAccess;
use Tests\TestCase;

/**
 * Enable Pennant flags for Pest procedural tests. Class-based tests can set
 * WithFeatures::$features instead; Pest files should call this helper.
 */
function usesFeatures(string ...$flags): void
{
    beforeEach(function () use ($flags): void {
        foreach ($flags as $flag) {
            Feature::define($flag, fn (): bool => true);
            // Database store persists the first resolved value; purge any stored
            // false from earlier tests so the new resolver actually wins.
            Feature::purge([$flag]);
        }
        Feature::flushCache();
    });
}

/**
 * Re-bind every config/features.php flag onto the current Pennant store.
 * Needed after switching stores (resolvers are per-driver).
 */
function redefinePennantFeaturesFromConfig(): void
{
    foreach (config('features', []) as $namespace => $flags) {
        if ($namespace === 'beta_bundle' || ! is_array($flags)) {
            continue;
        }

        foreach (array_keys($flags) as $leaf) {
            $name = "{$namespace}.{$leaf}";
            Feature::define($name, fn () => (bool) config("features.{$namespace}.{$leaf}", false));
        }
    }

    Feature::flushCache();
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind the application's base TestCase to every test under Feature and Unit
| so converted Pest tests boot the Laravel app exactly as the PHPUnit-class
| tests did. RefreshDatabase and other traits remain opt-in per file.
|
*/

uses(TestCase::class)->in('Feature', 'Unit');

uses(FakesRemoteServerAccess::class)->in('Feature');

/*
| Unit tests often skip RefreshDatabase. Pennant's default database store
| queries the features table before the config resolver — use the in-memory
| array store so Feature::active works without migrations. Feature tests keep
| the database store so per-org activate/deactivate overrides still persist.
|
| forgetInstance is required: Laravel may have already resolved the manager
| against the previous default during app boot / an earlier test.
*/
beforeEach(function (): void {
    config(['pennant.default' => 'array']);
    app()->forgetInstance(\Laravel\Pennant\FeatureManager::class);
    Feature::clearResolvedInstances();
    redefinePennantFeaturesFromConfig();
})->in('Unit');

beforeEach(function (): void {
    config(['pennant.default' => 'database']);
    app()->forgetInstance(\Laravel\Pennant\FeatureManager::class);
    Feature::clearResolvedInstances();
    // Boot-time FeatureServiceProvider definitions lived on the previous
    // manager instance — re-bind every config flag or Feature::active() falls
    // through undefined after forgetInstance (flaky page/Livewire aborts).
    redefinePennantFeaturesFromConfig();
    Feature::resolveScopeUsing(fn () => auth()->user()?->currentOrganization());
})->in('Feature');
