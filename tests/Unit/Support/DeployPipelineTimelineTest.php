<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployStep;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('splitForUi groups step hooks with their step block', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $step = $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_NPM_CI,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 900,
    ]);
    SiteDeployHook::query()->create([
        'site_id' => $site->id,
        'pipeline_id' => $pipeline->id,
        'phase' => SiteDeployHook::PHASE_AFTER_CLONE,
        'hook_kind' => SiteDeployHook::KIND_SHELL,
        'anchor' => SiteDeployHook::ANCHOR_AFTER_STEP,
        'anchor_step_id' => $step->id,
        'script' => 'echo ok',
        'sort_order' => 0,
        'timeout_seconds' => 60,
    ]);

    $split = DeployPipelineTimeline::splitForUi($pipeline->fresh(['steps', 'hooks']));

    expect($split['buildBlocks'])->toHaveCount(1)
        ->and($split['buildBlocks'][0]['hooks'])->toHaveCount(1)
        ->and(collect($split['prefix'])->contains(fn ($i) => $i['type'] === 'anchor' && $i['key'] === 'clone'))->toBeTrue()
        ->and(collect($split['mid'])->contains(fn ($i) => $i['type'] === 'anchor' && $i['key'] === 'activate'))->toBeTrue();
});

test('timeline places migrate in release zone after activate anchor', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 900,
    ]);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 20,
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'timeout_seconds' => 900,
    ]);

    $split = DeployPipelineTimeline::splitForUi($pipeline->fresh(['steps', 'hooks']));

    expect($split['buildBlocks'])->toHaveCount(1)
        ->and($split['releaseBlocks'])->toHaveCount(1)
        ->and($split['releaseBlocks'][0]['step']->step_type)->toBe(SiteDeployStep::TYPE_ARTISAN_MIGRATE);
});

test('before activate hooks appear in mid section', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    SiteDeployHook::query()->create([
        'site_id' => $site->id,
        'pipeline_id' => $pipeline->id,
        'phase' => SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
        'hook_kind' => SiteDeployHook::KIND_WEBHOOK,
        'anchor' => SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
        'webhook_url' => 'https://example.com/hook',
        'sort_order' => 0,
        'timeout_seconds' => 60,
    ]);

    $split = DeployPipelineTimeline::splitForUi($pipeline->fresh(['steps', 'hooks']));

    expect(collect($split['mid'])->contains(
        fn ($i) => $i['type'] === 'hook' && $i['hook']->anchor === SiteDeployHook::ANCHOR_BEFORE_ACTIVATE,
    ))->toBeTrue();
});
