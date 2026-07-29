<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineStarterApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('starter applier replaces steps and applies atomic rollout', function () {
    $site = Site::factory()->create([
        'deploy_strategy' => 'simple',
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
            'deploy_health_enabled' => false,
        ],
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'custom_command' => 'echo old',
        'timeout_seconds' => 60,
    ]);

    app(DeployPipelineStarterApplier::class)->apply(
        $site,
        $pipeline,
        'laravel-zero-downtime',
        activatePipeline: false,
    );

    $site->refresh();
    $pipeline->refresh()->load('steps');

    expect($site->deploy_strategy)->toBe('atomic')
        ->and((bool) data_get($site->meta, 'deploy_health_enabled'))->toBeTrue()
        ->and($pipeline->steps)->toHaveCount(4)
        ->and($pipeline->steps->where('step_type', SiteDeployStep::TYPE_CUSTOM)->count())->toBe(0);
});

test('safe laravel starter adds maintenance hooks', function () {
    $site = Site::factory()->create([
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ]);

    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    app(DeployPipelineStarterApplier::class)->apply(
        $site,
        $pipeline,
        'laravel-zero-downtime-safe',
    );

    expect($pipeline->fresh()->hooks)->toHaveCount(2);
});

test('pipeline counts use loaded relations without aggregate queries', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);

    $pipeline = $pipeline->fresh(['steps', 'hooks']);
    $applier = app(DeployPipelineStarterApplier::class);

    DB::enableQueryLog();

    expect($applier->pipelineIsEmpty($pipeline))->toBeFalse();
    $applier->previewSummaryLines($site, $pipeline, 'laravel-zero-downtime');

    $stepCountQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains((string) ($query['query'] ?? ''), 'site_deploy_steps')
            && str_contains((string) ($query['query'] ?? ''), 'count('));

    expect($stepCountQueries)->toBeEmpty();
});

test('starter applier activates pipeline when requested', function () {
    $site = Site::factory()->create();
    $manager = app(SiteDeployPipelineManager::class);
    $default = $manager->ensureDefaultPipeline($site);
    $manager->activatePipeline($site, $default);

    $staging = $manager->createPipeline($site, 'Staging');

    app(DeployPipelineStarterApplier::class)->apply(
        $site,
        $staging,
        'simple-in-place',
        activatePipeline: true,
    );

    expect($site->fresh()->active_deploy_pipeline_id)->toBe($staging->id);
});
