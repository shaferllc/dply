<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsTimeout;
use Tests\TestCase;

uses(TestCase::class);

class AsTimeoutTest extends Actions
{
    public function handle(): string
    {
        sleep(1); // Simulate work

        return 'completed';
    }

    protected function getTimeout(): int
    {
        return 2; // 2 seconds
    }
}

class TestTimeoutExceededAction extends Actions
{
    use AsTimeout;

    public function handle(): void
    {
        sleep(3); // Exceeds timeout
    }

    protected function getTimeout(): int
    {
        return 1; // 1 second timeout
    }
}

test('timeout allows execution within limit', function () {
    $action = TestTimeoutAction::make();

    $result = $action->handle();

    expect($result)->toBe('completed');
})->skip('Requires pcntl extension or different timeout mechanism');

test('timeout throws exception when exceeded', function () {
    $action = TestTimeoutExceededAction::make();

    expect(fn () => $action->handle())
        ->toThrow(\RuntimeException::class, 'timeout');
})->skip('Requires pcntl extension or different timeout mechanism');
