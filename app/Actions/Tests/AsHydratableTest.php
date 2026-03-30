<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use Tests\TestCase;

uses(TestCase::class);

test('hydratable fills properties from array', function () {
    $action = TestHydratableAction::make();

    $action->fill([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '123-456-7890',
    ]);

    expect($action->name)->toBe('John Doe')
        ->and($action->email)->toBe('john@example.com')
        ->and($action->phone)->toBe('123-456-7890');
});

test('hydratable respects fillable properties', function () {
    $action = TestHydratableWithFillableAction::make();

    $action->fill([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'internal' => 'should not be set',
    ]);

    expect($action->name)->toBe('John Doe')
        ->and($action->email)->toBe('john@example.com')
        ->and($action->internal)->toBe(''); // Should remain default
});

test('hydratable fills from object with toArray', function () {
    $object = new class
    {
        public function toArray(): array
        {
            return [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ];
        }
    };

    $action = TestHydratableAction::make();
    $action->fillFrom($object);

    expect($action->name)->toBe('Jane Doe')
        ->and($action->email)->toBe('jane@example.com');
});

test('hydratable fills from object with getAttributes', function () {
    $object = new class
    {
        public function getAttributes(): array
        {
            return [
                'name' => 'Bob Smith',
                'email' => 'bob@example.com',
            ];
        }
    };

    $action = TestHydratableAction::make();
    $action->fillFrom($object);

    expect($action->name)->toBe('Bob Smith')
        ->and($action->email)->toBe('bob@example.com');
});
