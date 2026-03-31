<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use Illuminate\Support\Fluent;
use Tests\TestCase;

uses(TestCase::class);

class AsObjectTestAction extends Actions
{
    public string $commandSignature = 'test:object-action';

    public function handle(string $value): string
    {
        return strtoupper($value);
    }
}

test('run executes action and returns result', function () {
    $result = AsObjectTestAction::run('hello');

    expect($result)->toBe('HELLO');
});

test('runIf executes when condition is true', function () {
    $result = AsObjectTestAction::runIf(true, 'hello');

    expect($result)->toBe('HELLO');
});

test('runIf returns Fluent when condition is false', function () {
    $result = AsObjectTestAction::runIf(false, 'hello');

    expect($result)->toBeInstanceOf(Fluent::class);
});

test('runUnless executes when condition is false', function () {
    $result = AsObjectTestAction::runUnless(false, 'hello');

    expect($result)->toBe('HELLO');
});

test('runUnless returns Fluent when condition is true', function () {
    $result = AsObjectTestAction::runUnless(true, 'hello');

    expect($result)->toBeInstanceOf(Fluent::class);
});

test('runWhen executes when callback returns true', function () {
    $result = AsObjectTestAction::runWhen(fn () => true, 'hello');

    expect($result)->toBe('HELLO');
});

test('runWhen returns Fluent when callback returns false', function () {
    $result = AsObjectTestAction::runWhen(fn () => false, 'hello');

    expect($result)->toBeInstanceOf(Fluent::class);
});

test('runSilently returns result on success', function () {
    $result = AsObjectTestAction::runSilently('hello');

    expect($result)->toBe('HELLO');
});

test('runSilently returns null on failure', function () {
    $action = new class extends Actions
    {
        public function handle(): never
        {
            throw new \RuntimeException('Failed');
        }
    };

    $result = $action::runSilently();

    expect($result)->toBeNull();
});

test('runWhenNotNull executes when value is not null', function () {
    $result = AsObjectTestAction::runWhenNotNull('hello');

    expect($result)->toBe('HELLO');
});

test('runWhenNotNull returns Fluent when value is null', function () {
    $result = AsObjectTestAction::runWhenNotNull(null);

    expect($result)->toBeInstanceOf(Fluent::class);
});

test('runWhenNotEmpty executes when value is not empty', function () {
    $result = AsObjectTestAction::runWhenNotEmpty('hi');

    expect($result)->toBe('HI');
});

test('runWhenNotEmpty returns Fluent when value is empty', function () {
    $result = AsObjectTestAction::runWhenNotEmpty('');

    expect($result)->toBeInstanceOf(Fluent::class);
});
