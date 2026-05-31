<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\SiteDeployPipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('updateStep moves step to release phase at end of release block', function () {
    $site = Site::factory()->create();
    $manager = app(SiteDeployPipelineManager::class);
    $pipeline = $manager->ensureDefaultPipeline($site);
    $build = $manager->addStep($pipeline, SiteDeployStep::TYPE_COMPOSER_INSTALL, null, 900, null, SiteDeployStep::PHASE_BUILD);
    $migrate = $manager->addStep($pipeline, SiteDeployStep::TYPE_ARTISAN_MIGRATE, null, 900, null, SiteDeployStep::PHASE_RELEASE);

    $manager->updateStep(
        $pipeline,
        $build,
        SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        null,
        900,
        SiteDeployStep::PHASE_RELEASE,
    );

    $ordered = $pipeline->fresh()->steps()->orderBy('sort_order')->pluck('id')->map(fn ($id) => (string) $id)->all();

    expect($ordered)->toBe([(string) $migrate->id, (string) $build->id]);
});
