<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDeployStepPhaseTest;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('default phase for artisan migrate is release', function () {
    expect(SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_ARTISAN_MIGRATE))->toBe(SiteDeployStep::PHASE_RELEASE);
});
test('default phase for artisan optimize is release', function () {
    expect(SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE))->toBe(SiteDeployStep::PHASE_RELEASE);
});
test('default phase for dependency installs is build', function () {
    foreach ([
        SiteDeployStep::TYPE_COMPOSER_INSTALL,
        SiteDeployStep::TYPE_NPM_CI,
        SiteDeployStep::TYPE_NPM_INSTALL,
        SiteDeployStep::TYPE_NPM_RUN,
    ] as $type) {
        expect(SiteDeployStep::defaultPhaseFor($type))->toBe(SiteDeployStep::PHASE_BUILD);
    }
});
test('default phase for artisan caches is build', function () {
    foreach ([
        SiteDeployStep::TYPE_ARTISAN_CONFIG_CACHE,
        SiteDeployStep::TYPE_ARTISAN_ROUTE_CACHE,
        SiteDeployStep::TYPE_ARTISAN_VIEW_CACHE,
    ] as $type) {
        expect(SiteDeployStep::defaultPhaseFor($type))->toBe(SiteDeployStep::PHASE_BUILD);
    }
});
test('default phase for one shot scaffolding is build', function () {
    expect(SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL))->toBe(SiteDeployStep::PHASE_BUILD);
    expect(SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL))->toBe(SiteDeployStep::PHASE_BUILD);
});
test('default phase for custom step is build', function () {
    expect(SiteDeployStep::defaultPhaseFor(SiteDeployStep::TYPE_CUSTOM))->toBe(SiteDeployStep::PHASE_BUILD);
});
test('default phase for unknown type falls back to build', function () {
    expect(SiteDeployStep::defaultPhaseFor('something_new'))->toBe(SiteDeployStep::PHASE_BUILD);
});
test('user phases excludes swap and restart', function () {
    $userPhases = SiteDeployStep::userPhases();

    expect($userPhases)->toContain(SiteDeployStep::PHASE_BUILD);
    expect($userPhases)->toContain(SiteDeployStep::PHASE_RELEASE);
    expect($userPhases)->not->toContain(SiteDeployStep::PHASE_SWAP);
    expect($userPhases)->not->toContain(SiteDeployStep::PHASE_RESTART);
});
test('all phases in canonical pipeline order', function () {
    expect(SiteDeployStep::allPhases())->toBe(['build', 'swap', 'release', 'restart']);
});
test('phase scope filters to a single phase', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 1,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 600,
    ]);
    SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 2,
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'timeout_seconds' => 600,
    ]);

    $build = SiteDeployStep::query()
        ->where('site_id', $site->id)
        ->phase(SiteDeployStep::PHASE_BUILD)
        ->get();
    $release = SiteDeployStep::query()
        ->where('site_id', $site->id)
        ->phase(SiteDeployStep::PHASE_RELEASE)
        ->get();

    expect($build)->toHaveCount(1);
    expect($release)->toHaveCount(1);
    expect($build->first()->step_type)->toBe(SiteDeployStep::TYPE_COMPOSER_INSTALL);
    expect($release->first()->step_type)->toBe(SiteDeployStep::TYPE_ARTISAN_MIGRATE);
});
test('phase column defaults to build when not set', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    // Create without setting phase — DB default should kick in.
    $step = SiteDeployStep::create([
        'site_id' => $site->id,
        'sort_order' => 1,
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'timeout_seconds' => 600,
    ]);

    expect($step->refresh()->phase)->toBe(SiteDeployStep::PHASE_BUILD);
});
