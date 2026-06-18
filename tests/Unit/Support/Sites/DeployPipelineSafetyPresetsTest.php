<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineSafetyPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function laravelSiteForSafetyPresets(): Site
{
    return Site::factory()->create([
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);
}

test('laravel safety bundle adds maintenance hooks pretend and backup', function () {
    $site = laravelSiteForSafetyPresets();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    $result = app(DeployPipelineSafetyPresets::class)->apply(
        DeployPipelineSafetyPresets::BUNDLE_LARAVEL_V1,
        $pipeline,
        $site,
    );

    expect($result['hooks_added'])->toBe(2)
        ->and($result['steps_added'])->toBe(2);

    $pipeline->refresh()->load(['hooks', 'steps']);

    expect($pipeline->hooks->where('anchor', SiteDeployHook::ANCHOR_BEFORE_ACTIVATE)->count())->toBe(1)
        ->and($pipeline->hooks->where('anchor', SiteDeployHook::ANCHOR_AFTER_ACTIVATE)->count())->toBe(1)
        ->and($pipeline->steps->where('step_type', SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND)->count())->toBe(1)
        ->and($pipeline->steps->where('step_type', SiteDeployStep::TYPE_CUSTOM)->count())->toBe(1);
});

test('laravel safety bundle is idempotent on second apply', function () {
    $site = laravelSiteForSafetyPresets();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $presets = app(DeployPipelineSafetyPresets::class);

    $presets->apply(DeployPipelineSafetyPresets::BUNDLE_LARAVEL_V1, $pipeline, $site);
    $second = $presets->apply(DeployPipelineSafetyPresets::BUNDLE_LARAVEL_V1, $pipeline->fresh(), $site);

    expect($second['hooks_added'])->toBe(0)
        ->and($second['steps_added'])->toBe(0);
});

test('laravel safety bundle rejects non-laravel sites', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    app(DeployPipelineSafetyPresets::class)->apply(
        DeployPipelineSafetyPresets::BUNDLE_LARAVEL_V1,
        $pipeline,
        $site,
    );
})->throws(InvalidArgumentException::class);

test('migrate pretend step type produces runnable command', function () {
    $step = new SiteDeployStep([
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE_PRETEND,
    ]);

    expect($step->commandFor())->toBe('php artisan migrate --pretend --no-interaction');
});
