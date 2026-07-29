<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineScriptExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('bash full export includes build and release sections', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'custom_command' => 'npm ci',
        'timeout_seconds' => 900,
    ]);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 20,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'custom_command' => 'php artisan migrate --force',
        'timeout_seconds' => 600,
    ]);

    $bash = app(DeployPipelineScriptExporter::class)->toFullBash($pipeline->fresh(['steps', 'hooks']));

    expect($bash)->toContain('# --- Build ---')
        ->and($bash)->toContain('npm ci')
        ->and($bash)->toContain('# --- Release ---')
        ->and($bash)->toContain('reference only');
});

test('bash commands only export lists build and release commands', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);

    $bash = app(DeployPipelineScriptExporter::class)->toCommandsOnly($pipeline->fresh(['steps']));

    expect($bash)->toContain('composer install')
        ->and($bash)->not->toContain('Before clone');
});
