<?php


namespace Tests\Unit\SiteOctaneTest;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('octane server defaults to swoole', function () {
    $site = Site::factory()->create(['meta' => null]);

    expect($site->fresh()->octaneServer())->toBe('swoole');
});

test('octane server reads meta and invalid falls back', function () {
    $site = Site::factory()->create([
        'meta' => ['laravel_octane' => ['server' => 'roadrunner']],
    ]);

    expect($site->fresh()->octaneServer())->toBe('roadrunner');

    $site->update(['meta' => ['laravel_octane' => ['server' => 'bogus']]]);
    expect($site->fresh()->octaneServer())->toBe('swoole');
});

test('octane supervisor command uses port and server', function () {
    $site = Site::factory()->create([
        'octane_port' => 9001,
        'meta' => ['laravel_octane' => ['server' => 'frankenphp']],
    ]);

    expect($site->fresh()->octaneSupervisorCommand())->toBe('php artisan octane:start --server=frankenphp --host=127.0.0.1 --port=9001');
});

test('octane supervisor command defaults port when missing', function () {
    $site = Site::factory()->create([
        'octane_port' => null,
        'meta' => ['laravel_octane' => ['server' => 'swoole']],
    ]);

    expect($site->fresh()->octaneSupervisorCommand())->toBe('php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000');
});

test('should show octane runtime ui requires laravel detection and composer flag', function () {
    $noDetection = Site::factory()->create(['meta' => null]);
    expect($noDetection->shouldShowOctaneRuntimeUi())->toBeFalse();

    $laravelOnly = Site::factory()->create([
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                ],
            ],
        ],
    ]);
    expect($laravelOnly->fresh()->shouldShowOctaneRuntimeUi())->toBeFalse();

    $withOctane = Site::factory()->create([
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'laravel_octane' => true,
                ],
            ],
        ],
    ]);
    expect($withOctane->fresh()->shouldShowOctaneRuntimeUi())->toBeTrue();
});

test('detected laravel package keys lists composer packages', function () {
    $site = Site::factory()->create([
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                    'language' => 'php',
                    'laravel_horizon' => true,
                    'laravel_reverb' => true,
                ],
            ],
        ],
    ]);

    expect($site->fresh()->detectedLaravelPackageKeys())->toBe(['horizon', 'reverb']);
});

test('reverb supervisor command line uses meta or override', function () {
    $site = Site::factory()->create([
        'meta' => ['laravel_reverb' => ['port' => 9090]],
    ]);

    expect($site->fresh()->reverbSupervisorCommandLine())->toBe('php artisan reverb:start --host=0.0.0.0 --port=9090');
    expect($site->fresh()->reverbSupervisorCommandLine(7777))->toBe('php artisan reverb:start --host=0.0.0.0 --port=7777');
});