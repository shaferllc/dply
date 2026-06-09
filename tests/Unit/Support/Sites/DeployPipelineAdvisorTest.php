<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployStep;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Support\Sites\DeployPipelineAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function laravelSiteForPipelineAdvisor(array $attrs = []): Site
{
    return Site::factory()->create(array_merge([
        'deploy_strategy' => 'atomic',
        'meta' => [
            'vm_runtime' => [
                'detected' => ['framework' => 'laravel', 'language' => 'php'],
            ],
        ],
    ], $attrs));
}

test('pipeline advisor flags migrate in build phase', function () {
    $site = laravelSiteForPipelineAdvisor();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'timeout_seconds' => 900,
    ]);

    $result = app(DeployPipelineAdvisor::class)->analyze($site, $pipeline->fresh(['steps', 'hooks']));

    expect($result['ok'])->toBeTrue()
        ->and($result['warnings'])->not->toBeEmpty()
        ->and(collect($result['checks'])->pluck('key'))->toContain('release_step_in_build_'.SiteDeployStep::TYPE_ARTISAN_MIGRATE);
});

test('pipeline advisor flags empty custom step command', function () {
    $site = Site::factory()->create();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'phase' => SiteDeployStep::PHASE_BUILD,
        'custom_command' => null,
        'timeout_seconds' => 900,
    ]);

    $result = app(DeployPipelineAdvisor::class)->analyze($site, $pipeline->fresh(['steps', 'hooks']));

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

test('pipeline advisor flags migrate without pretend on laravel sites', function () {
    $site = laravelSiteForPipelineAdvisor();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'timeout_seconds' => 900,
    ]);

    $result = app(DeployPipelineAdvisor::class)->analyze($site, $pipeline->fresh(['steps', 'hooks']));

    expect(collect($result['checks'])->pluck('key'))->toContain('migrate_without_pretend');
});

test('pipeline advisor flags maintenance down after activate', function () {
    $site = laravelSiteForPipelineAdvisor();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    $pipeline->hooks()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'phase' => SiteDeployHook::PHASE_AFTER_ACTIVATE,
        'hook_kind' => SiteDeployHook::KIND_SHELL,
        'anchor' => SiteDeployHook::ANCHOR_AFTER_ACTIVATE,
        'script' => "php artisan down\n",
        'timeout_seconds' => 120,
    ]);

    $result = app(DeployPipelineAdvisor::class)->analyze($site, $pipeline->fresh(['steps', 'hooks']));

    expect(collect($result['checks'])->contains(
        fn (array $c) => str_starts_with((string) ($c['key'] ?? ''), 'maintenance_down_late_'),
    ))->toBeTrue();
});

test('pipeline advisor flags optimize before migrate in release', function () {
    $site = laravelSiteForPipelineAdvisor();
    $pipeline = app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($site);

    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 10,
        'step_type' => SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'timeout_seconds' => 900,
    ]);
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'sort_order' => 20,
        'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
        'phase' => SiteDeployStep::PHASE_RELEASE,
        'timeout_seconds' => 900,
    ]);

    $result = app(DeployPipelineAdvisor::class)->analyze($site, $pipeline->fresh(['steps', 'hooks']));

    expect(collect($result['checks'])->pluck('key'))->toContain('optimize_before_migrate');
});
