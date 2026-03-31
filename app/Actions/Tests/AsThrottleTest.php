<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsThrottle;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

class AsThrottleTest extends Actions
{
    use AsThrottle;

    public int $executions = 0;

    public function handle(): string
    {
        $this->executions++;

        return 'executed';
    }

    protected function getMaxConcurrent(): int
    {
        return 2;
    }
}

test('throttle allows execution within limit', function () {
    Cache::flush();
    $action = AsThrottleTest::make();

    $result1 = $action->handle();
    $result2 = $action->handle();

    expect($result1)->toBe('executed')
        ->and($result2)->toBe('executed')
        ->and($action->executions)->toBe(2);
});

test('throttle prevents exceeding max concurrent', function () {
    Cache::flush();
    $action = AsThrottleTest::make();
    $action->maxConcurrent = 1;

    // First execution succeeds
    $result1 = $action->handle();

    // Simulate concurrent execution by manually incrementing cache
    $key = $action->getThrottleKey([]);
    Cache::put($key, 1, 300);

    // Second execution should fail
    expect(fn () => $action->handle())
        ->toThrow(\RuntimeException::class, 'Maximum concurrent executions');

    Cache::forget($key);
});

test('throttle decrements counter after execution', function () {
    Cache::flush();
    $action = AsThrottleTest::make();

    $action->handle();
    $action->handle();

    // Counter should be decremented, allowing more executions
    $result3 = $action->handle();

    expect($result3)->toBe('executed');
});

test('throttle decrements counter even on exception', function () {
    Cache::flush();

    $action = new class extends Actions
    {
        use AsThrottle;

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

    // Counter should be decremented, allowing retry
    try {
        $action->handle();
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('Test error');
    }
});
