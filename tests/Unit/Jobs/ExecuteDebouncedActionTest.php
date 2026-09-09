<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\ExecuteDebouncedActionTest;

use App\Jobs\ExecuteDebouncedAction;
use Illuminate\Support\Facades\Cache;

final class ExecuteDebouncedActionTestAction
{
    /** @var list<string> */
    public static array $handled = [];

    public function handle(string $value): void
    {
        self::$handled[] = $value;
    }
}

beforeEach(function () {
    ExecuteDebouncedActionTestAction::$handled = [];
});

test('executes the action with cached arguments after the quiet period', function () {
    $cacheKey = 'debounce:'.ExecuteDebouncedActionTestAction::class.':test';

    Cache::put($cacheKey, [
        'arguments' => ['latest'],
        'scheduled_at' => now()->subSecond(),
    ], 60);

    (new ExecuteDebouncedAction(
        ExecuteDebouncedActionTestAction::class,
        $cacheKey,
        ['stale'],
    ))->handle();

    expect(ExecuteDebouncedActionTestAction::$handled)->toBe(['latest']);
    expect(Cache::get($cacheKey))->toBeNull();
});

test('skips execution when a newer call pushed the quiet period forward', function () {
    $cacheKey = 'debounce:'.ExecuteDebouncedActionTestAction::class.':later';

    Cache::put($cacheKey, [
        'arguments' => ['held'],
        'scheduled_at' => now()->addSeconds(30),
    ], 60);

    (new ExecuteDebouncedAction(
        ExecuteDebouncedActionTestAction::class,
        $cacheKey,
        ['held'],
    ))->handle();

    expect(ExecuteDebouncedActionTestAction::$handled)->toBe([]);
    expect(Cache::get($cacheKey))->not->toBeNull();
});

test('no-ops when the pending payload was already consumed', function () {
    (new ExecuteDebouncedAction(
        ExecuteDebouncedActionTestAction::class,
        'debounce:missing',
        ['gone'],
    ))->handle();

    expect(ExecuteDebouncedActionTestAction::$handled)->toBe([]);
});
