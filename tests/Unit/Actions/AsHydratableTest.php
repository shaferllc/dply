<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\AsHydratableTest;

use App\Actions\Tests\TestHydratableAction;
use App\Actions\Tests\TestHydratableWithFillableAction;

/**
 * Guards a crash, not a behaviour: getFillable() used to reflect on $this,
 * compare the declaring class against the trait, and invoke what it found.
 * PHP flattens a trait method INTO the using class, so that comparison could
 * never be false and every call re-invoked itself until the stack ran out and
 * the process died with SIGSEGV.
 *
 * It lives in the default suite deliberately. The App suite that owns
 * app/Actions/Tests is not green yet and is excluded from `composer test`, so a
 * guard there would protect nothing. A regression here takes the process down
 * rather than failing an assertion — which is exactly the signal wanted.
 */
it('fills an action whose class does not override getFillable', function () {
    $action = new TestHydratableAction;

    $action->fill(['name' => 'John Doe', 'email' => 'john@example.com', 'phone' => '555']);

    expect($action->name)->toBe('John Doe')
        ->and($action->email)->toBe('john@example.com')
        ->and($action->phone)->toBe('555');
});

it('still honours a class-declared fillable list', function () {
    // A class method beats a trait method in PHP, so plain dispatch reaches the
    // override. That is what replaced the reflection.
    $action = new TestHydratableWithFillableAction;

    $action->fill(['name' => 'John Doe', 'email' => 'john@example.com', 'internal' => 'should not be set']);

    expect($action->name)->toBe('John Doe')
        ->and($action->internal)->toBe('');
});

it('ignores keys that are not declared properties', function () {
    $action = new TestHydratableAction;

    $action->fill(['name' => 'Jane', 'not_a_property' => 'x']);

    expect($action->name)->toBe('Jane')
        ->and(property_exists($action, 'not_a_property'))->toBeFalse();
});
