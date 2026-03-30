<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLogger;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

test('logger logs action start and success', function () {
    Log::spy();

    $action = TestLoggerAction::make();
    $result = $action->handle('test');

    expect($result)->toBe('processed: test');

    Log::shouldHaveReceived('channel')
        ->with('stack')
        ->twice();

    Log::shouldHaveReceived('info')
        ->with('Action started', \Mockery::type('array'))
        ->once();

    Log::shouldHaveReceived('info')
        ->with('Action completed', \Mockery::type('array'))
        ->once();
});

test('logger uses custom channel', function () {
    Log::spy();

    $action = TestLoggerWithCustomChannelAction::make();
    $action->handle();

    Log::shouldHaveReceived('channel')
        ->with('custom')
        ->atLeast()->once();
});

test('logger logs errors', function () {
    Log::spy();

    $action = TestLoggerFailingAction::make();

    expect(fn () => $action->handle())
        ->toThrow(\RuntimeException::class, 'Test error');

    Log::shouldHaveReceived('error')
        ->with('Action failed', \Mockery::type('array'))
        ->once();
});

test('logger sanitizes sensitive parameters', function () {
    Log::spy();

    $action = new class extends Actions
    {
        use AsLogger;

        public function handle(string $password): string
        {
            return 'success';
        }

        protected function getSensitiveParameters(): array
        {
            return ['password'];
        }
    };

    $action->handle('secret123');

    Log::shouldHaveReceived('info')
        ->with('Action started', \Mockery::on(function ($data) {
            return isset($data['parameters'][0]) && $data['parameters'][0] === '***REDACTED***';
        }))
        ->once();
});
