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
        }
        Feature::flushCache();
    });
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
