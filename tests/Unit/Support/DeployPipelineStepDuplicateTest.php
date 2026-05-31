<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineStepDuplicate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('detects duplicate preset step type on pipeline', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 900,
    ]);

    expect(DeployPipelineStepDuplicate::exists($pipeline, SiteDeployStep::TYPE_COMPOSER_INSTALL))->toBeTrue()
        ->and(DeployPipelineStepDuplicate::exists($pipeline, SiteDeployStep::TYPE_NPM_CI))->toBeFalse();
});

test('custom steps only duplicate when command matches', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'custom_command' => 'php artisan horizon',
        'timeout_seconds' => 900,
    ]);

    expect(DeployPipelineStepDuplicate::exists($pipeline, SiteDeployStep::TYPE_CUSTOM, 'php artisan horizon'))->toBeTrue()
        ->and(DeployPipelineStepDuplicate::exists($pipeline, SiteDeployStep::TYPE_CUSTOM, 'npm run build'))->toBeFalse();
});
