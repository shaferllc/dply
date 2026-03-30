<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

test('stateful maintains state across calls', function () {
    Cache::flush();
    $action = TestStatefulAction::make();

    $action->handle('step1', ['data' => 'value1']);
    $action->handle('step2', ['data' => 'value2']);

    $state = $action->getState();

    expect($state)->toBeArray()
        ->and($state['step1'])->toBe(['data' => 'value1'])
        ->and($state['step2'])->toBe(['data' => 'value2']);
});

test('stateful uses custom state key', function () {
    Cache::flush();
    $action = TestStatefulWithCustomKeyAction::make();

    $action->handle('test-value');

    expect(Cache::has('custom:state:key'))->toBeTrue()
        ->and(Cache::get('custom:state:key')['value'])->toBe('test-value');
});

test('stateful clears state', function () {
    Cache::flush();
    $action = TestStatefulAction::make();

    $action->handle('step1', ['data' => 'value1']);
    $action->clearState();

    $state = $action->getState();

    expect($state)->toBeEmpty();
});

test('stateful persists state in cache', function () {
    Cache::flush();
    $action1 = TestStatefulAction::make();
    $action1->handle('step1', ['data' => 'value1']);

    // New instance should have same state
    $action2 = TestStatefulAction::make();
    $state = $action2->getState();

    expect($state['step1'])->toBe(['data' => 'value1']);
});
