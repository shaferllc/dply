<?php

declare(strict_types=1);

use App\Models\Site;
use App\Support\Sites\DeployPipelineStarterCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('starter catalog includes universal and laravel starters for laravel site', function () {
    $site = Site::factory()->create([
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);

    $keys = collect(app(DeployPipelineStarterCatalog::class)->startersForSite($site))->pluck('key')->all();

    expect($keys)->toContain('simple-in-place', 'zero-downtime', 'laravel-zero-downtime-safe')
        ->and($keys)->not->toContain('rails-zero-downtime');
});

test('simple universal starter moves migrate to build phase', function () {
    $site = Site::factory()->create([
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);

    $steps = app(DeployPipelineStarterCatalog::class)->resolveSteps($site, 'simple-in-place');

    $migrate = collect($steps)->firstWhere('step_type', 'artisan_migrate');

    expect($migrate)->not->toBeNull()
        ->and($migrate['phase'])->toBe('build');
});

test('zero downtime rollout enables health check with laravel up path', function () {
    $site = Site::factory()->create([
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);

    $rollout = app(DeployPipelineStarterCatalog::class)->rolloutChangesFor($site, 'laravel-zero-downtime');

    expect($rollout['deploy_strategy'])->toBe('atomic')
        ->and($rollout['deploy_health_enabled'])->toBeTrue()
        ->and($rollout['deploy_health_path'])->toBe('/up');
});

test('universal zero downtime starter includes laravel release steps for php site without detection meta', function () {
    $site = Site::factory()->create([
        'runtime' => 'php',
        'meta' => [],
    ]);

    $steps = app(DeployPipelineStarterCatalog::class)->resolveSteps($site, 'zero-downtime');

    $releaseTypes = collect($steps)
        ->where('phase', 'release')
        ->pluck('step_type')
        ->all();

    expect($releaseTypes)->toContain('artisan_migrate', 'artisan_optimize');
});

test('laravel starters visible for php runtime without vm_runtime detection', function () {
    $site = Site::factory()->create([
        'runtime' => 'php',
        'meta' => [],
    ]);

    $keys = collect(app(DeployPipelineStarterCatalog::class)->startersForSite($site))->pluck('key')->all();

    expect($keys)->toContain('laravel-zero-downtime', 'zero-downtime');
});

test('simple rollout disables deploy health check', function () {
    $site = Site::factory()->create([
        'deploy_strategy' => 'atomic',
        'meta' => ['deploy_health_enabled' => true, 'deploy_health_auto_rollback' => true],
    ]);

    $rollout = app(DeployPipelineStarterCatalog::class)->rolloutChangesFor($site, 'simple-in-place');

    expect($rollout['deploy_strategy'])->toBe('simple')
        ->and($rollout['deploy_health_enabled'])->toBeFalse()
        ->and($rollout['deploy_health_auto_rollback'])->toBeFalse();
});
