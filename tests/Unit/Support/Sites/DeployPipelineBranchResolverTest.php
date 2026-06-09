<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineBranchResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('branch resolver picks pipeline with matching deploy_branches', function () {
    $site = Site::factory()->create(['git_branch' => 'develop']);
    $manager = app(SiteDeployPipelineManager::class);
    $default = $manager->ensureDefaultPipeline($site);
    $manager->activatePipeline($site, $default);

    $staging = $manager->createPipeline($site, 'Staging');
    $staging->update(['deploy_branches' => ['develop', 'staging/*']]);

    $resolved = app(DeployPipelineBranchResolver::class)->resolveForBranch($site, 'develop');

    expect((string) $resolved->id)->toBe((string) $staging->id);
});

test('branch resolver falls back to active pipeline when no branch match', function () {
    $site = Site::factory()->create(['git_branch' => 'main']);
    $manager = app(SiteDeployPipelineManager::class);
    $default = $manager->ensureDefaultPipeline($site);
    $staging = $manager->createPipeline($site, 'Staging');
    $staging->update(['deploy_branches' => ['develop']]);
    $manager->activatePipeline($site, $default);

    $resolved = app(DeployPipelineBranchResolver::class)->resolveForBranch($site, 'main');

    expect((string) $resolved->id)->toBe((string) $default->id);
});

test('applyForDeploy sets in-memory active pipeline id without persisting', function () {
    $site = Site::factory()->create(['git_branch' => 'feature/foo']);
    $manager = app(SiteDeployPipelineManager::class);
    $default = $manager->ensureDefaultPipeline($site);
    $manager->activatePipeline($site, $default);

    $feature = $manager->createPipeline($site, 'Feature');
    $feature->update(['deploy_branches' => ['feature/*']]);

    app(DeployPipelineBranchResolver::class)->applyForDeploy($site, 'feature/foo');

    expect($site->active_deploy_pipeline_id)->toBe($feature->id)
        ->and($site->fresh()->active_deploy_pipeline_id)->toBe($default->id);
});

test('branch resolver supports wildcard patterns', function () {
    $site = Site::factory()->create();
    $manager = app(SiteDeployPipelineManager::class);
    $default = $manager->ensureDefaultPipeline($site);

    $release = $manager->createPipeline($site, 'Release');
    $release->update(['deploy_branches' => ['release/*']]);

    $resolved = app(DeployPipelineBranchResolver::class)->resolveForBranch($site, 'release/1.2');

    expect($resolved)->toBeInstanceOf(SiteDeployPipeline::class)
        ->and((string) $resolved->id)->toBe((string) $release->id)
        ->and((string) $default->id)->not->toBe((string) $release->id);
});
