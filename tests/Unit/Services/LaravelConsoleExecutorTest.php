<?php


namespace Tests\Unit\Services\LaravelConsoleExecutorTest;
use App\Models\Site;
use App\Services\Sites\LaravelConsoleExecutor;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('assert safe artisan argv rejects shell metacharacters', function () {
    $executor = app(LaravelConsoleExecutor::class);

    $executor->assertSafeArtisanArgv('cache:clear');

    $this->expectException(\InvalidArgumentException::class);
    $executor->assertSafeArtisanArgv('cache:clear; rm -rf /');
});

test('preset command recognized', function () {
    $executor = app(LaravelConsoleExecutor::class);

    expect($executor->isPresetCommand('cache:clear'))->toBeTrue();
    expect($executor->isPresetCommand('made:up'))->toBeFalse();
});

test('custom commands read from meta', function () {
    $site = Site::factory()->create([
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
            'laravel_console' => [
                'custom_commands' => ['migrate --force', 'db:seed'],
            ],
        ],
    ]);

    $executor = app(LaravelConsoleExecutor::class);

    expect($executor->customCommands($site->fresh()))->toBe(['migrate --force', 'db:seed']);
});