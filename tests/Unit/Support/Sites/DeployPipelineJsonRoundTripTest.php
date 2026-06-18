<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineJsonExporter;
use App\Support\Sites\DeployPipelineJsonImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('pipeline json export and import round trip', function () {
    $site = Site::factory()->create([
        'deploy_strategy' => 'atomic',
        'meta' => ['deploy_health_enabled' => true],
    ]);
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $pipeline->update([
        'deploy_branches' => ['main'],
        'clone_script' => 'echo clone',
    ]);

    $step = $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'custom_command' => 'npm ci',
        'timeout_seconds' => 900,
    ]);

    $pipeline->hooks()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'phase' => SiteDeployHook::ANCHOR_AFTER_STEP,
        'hook_kind' => SiteDeployHook::KIND_SHELL,
        'anchor' => SiteDeployHook::ANCHOR_AFTER_STEP,
        'anchor_step_id' => $step->id,
        'script' => "echo after\n",
        'timeout_seconds' => 120,
    ]);

    $json = app(DeployPipelineJsonExporter::class)->export($site, $pipeline->fresh(['steps', 'hooks']));

    $target = app(SiteDeployPipelineManager::class)->createPipeline($site, 'Imported');
    app(DeployPipelineJsonImporter::class)->apply($site, $target, $json, applyRollout: true);

    $target->refresh()->load(['steps', 'hooks']);
    $site->refresh();

    expect($target->deploy_branches)->toBe(['main'])
        ->and($target->clone_script)->toBe('echo clone')
        ->and($target->steps)->toHaveCount(1)
        ->and($target->hooks)->toHaveCount(1)
        ->and($site->deploy_strategy)->toBe('atomic')
        ->and((bool) data_get($site->meta, 'deploy_health_enabled'))->toBeTrue();
});

test('pipeline json import rejects unknown step type', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    $json = json_encode([
        'version' => 1,
        'pipeline' => [
            'steps' => [['step_type' => 'not_a_real_type', 'phase' => 'build']],
            'hooks' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    app(DeployPipelineJsonImporter::class)->apply($site, $pipeline, $json);
})->throws(InvalidArgumentException::class);
