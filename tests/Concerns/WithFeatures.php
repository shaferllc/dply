<?php

namespace Tests\Concerns;

use Laravel\Pennant\Feature;

/**
 * Enables the listed Pennant flags for the duration of a test case.
 *
 * Tests inherit production defaults (mostly OFF). To exercise a flagged
 * surface, add this trait and declare the flags:
 *
 *   class ClusterTabTest extends TestCase
 *   {
 *       use WithFeatures;
 *
 *       protected array $features = ['workspace.cluster'];
 *   }
 *
 * Each declared flag is re-defined in setUp() to always return true,
 * regardless of scope, so the test does not need an authenticated user
 * or organization context to flip flags on.
 */
trait WithFeatures
{
    protected function setUpWithFeatures(): void
    {
        foreach ($this->features ?? [] as $flag) {
            Feature::define($flag, fn () => true);
        }
        Feature::flushCache();
    }

    protected function tearDownWithFeatures(): void
    {
        // Re-register from config so a later test class isn't sticky to our overrides.
        foreach (config('features', []) as $namespace => $flags) {
            foreach ($flags as $leaf => $default) {
                Feature::define("$namespace.$leaf", fn () => (bool) $default);
            }
        }
        Feature::flushCache();
    }
}
