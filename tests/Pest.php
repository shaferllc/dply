<?php

use Tests\Concerns\FakesRemoteServerAccess;
use Tests\TestCase;

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
