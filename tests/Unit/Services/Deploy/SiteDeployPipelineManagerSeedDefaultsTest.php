<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeding runtime defaults twice does not add a second composer install', function () {
    $site = Site::factory()->create(['runtime' => 'php']);
    $manager = app(SiteDeployPipelineManager::class);

    $manager->seedRuntimeDefaults($site, 'php', 'laravel');
    $manager->seedRuntimeDefaults($site, 'php', null);

    $pipeline = $manager->ensureDefaultPipeline($site);
    $composerSteps = $pipeline->steps()
        ->where('step_type', SiteDeployStep::TYPE_COMPOSER_INSTALL)
        ->where('phase', SiteDeployStep::PHASE_BUILD)
        ->count();

    expect($composerSteps)->toBe(1);
});

test('seeding laravel defaults after php defaults adds missing steps only', function () {
    $site = Site::factory()->create(['runtime' => 'php']);
    $manager = app(SiteDeployPipelineManager::class);

    $manager->seedRuntimeDefaults($site, 'php', null);
    $manager->seedRuntimeDefaults($site, 'php', 'laravel');

    $pipeline = $manager->ensureDefaultPipeline($site);
    $types = $pipeline->steps()->orderBy('sort_order')->pluck('step_type')->all();

    expect($types)->toBe([
        SiteDeployStep::TYPE_COMPOSER_INSTALL,
        SiteDeployStep::TYPE_NPM_CI,
        SiteDeployStep::TYPE_NPM_RUN,
        SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
    ]);
});

test('seeding collapses an existing duplicate composer install', function () {
    $site = Site::factory()->create(['runtime' => 'php']);
    $manager = app(SiteDeployPipelineManager::class);
    $pipeline = $manager->ensureDefaultPipeline($site);

    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 20,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);

    $manager->seedRuntimeDefaults($site, 'php', null);

    expect($pipeline->steps()->where('step_type', SiteDeployStep::TYPE_COMPOSER_INSTALL)->count())->toBe(1);
});
