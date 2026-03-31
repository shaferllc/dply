<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLock;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

test('lock prevents concurrent execution', function () {
    Cache::flush();
    $action = TestLockAction::make();

    // Acquire lock
    $lock = Cache::lock($action->getLockKey(), 10);
    $lock->get();

    // Try to execute - should fail
    expect(fn () => $action->handle())
        ->toThrow(\RuntimeException::class, 'Could not acquire lock');

    $lock->release();
});

test('lock allows execution when available', function () {
    Cache::flush();
    $action = TestLockAction::make();

    $result = $action->run();

    expect($result)->toBe('locked')
        ->and($action->executions)->toBe(1);
});

test('lock uses custom key', function () {
    Cache::flush();
    $action = TestLockWithCustomKeyAction::make();

    $result = $action->handle(123);

    expect($result)->toBe('processed: 123');

    // Verify lock was created with custom key
    $lock = Cache::lock('lock:custom:123', 10);
    expect($lock->get())->toBeTrue(); // Lock should be available after execution
    $lock->release();
});

test('lock releases after execution', function () {
    Cache::flush();
    $action = TestLockAction::make();

    $action->handle();
    $action->handle(); // Should succeed - lock released

    expect($action->executions)->toBe(2);
});

test('lock releases even on exception', function () {
    Cache::flush();

    $action = new class extends Actions
    {
        use AsLock;

        public function handle(): void
        {
            throw new \RuntimeException('Test error');
        }
    };

    try {
        $action->handle();
    } catch (\RuntimeException $e) {
        // Expected
    }

    // Lock should be released, allowing another execution
    $action2 = new class extends Actions
    {
        use AsLock;

        public function handle(): string
        {
            return 'success';
        }
    };

    expect($action2->handle())->toBe('success');
});
