<?php

declare(strict_types=1);

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Support\Sites\DeployPipelinePalette;

test('deploy pipeline palette filters laravel steps for laravel sites', function () {
    $site = Site::factory()->make([
        'runtime' => 'php',
        'meta' => ['vm_runtime' => ['detected' => ['framework' => 'laravel']]],
    ]);

    $types = collect(DeployPipelinePalette::stepsFor($site))
        ->pluck('type')
        ->unique()
        ->all();

    expect($types)->toContain(SiteDeployStep::TYPE_ARTISAN_MIGRATE)
        ->and($types)->not->toContain(SiteDeployStep::TYPE_NPM_CI);
});

test('deploy pipeline palette includes node steps for node runtime', function () {
    $site = Site::factory()->make([
        'runtime' => 'node',
    ]);

    $types = collect(DeployPipelinePalette::stepsFor($site))
        ->pluck('type')
        ->unique()
        ->all();

    expect($types)->toContain(SiteDeployStep::TYPE_NPM_CI)
        ->and($types)->not->toContain(SiteDeployStep::TYPE_ARTISAN_MIGRATE);
});

test('deploy pipeline step catalog includes all palette entries', function () {
    $site = Site::factory()->make(['runtime' => 'php']);

    $catalogEntries = collect(DeployPipelinePalette::stepCatalogFor($site))
        ->flatMap(fn (array $group) => $group['entries'])
        ->count();

    expect($catalogEntries)->toBe(count(DeployPipelinePalette::allPaletteEntries()));
});

test('deploy pipeline step catalog marks gated entries when not applicable', function () {
    $site = Site::factory()->make(['runtime' => 'node']);

    $migrate = collect(DeployPipelinePalette::stepCatalogFor($site))
        ->flatMap(fn (array $group) => $group['entries'])
        ->first(fn (array $e) => $e['type'] === SiteDeployStep::TYPE_ARTISAN_MIGRATE);

    expect($migrate)->not->toBeNull()
        ->and($migrate['visible'])->toBeFalse()
        ->and($migrate['requires_label'])->toBe('Laravel');
});

test('deploy pipeline step type reference lists every built-in type', function () {
    expect(DeployPipelinePalette::stepTypeReference())->toHaveCount(count(SiteDeployStep::typeLabels()));
});
