<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteDeployStepsRuntimeReconcilerTest;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use App\Services\Sites\SiteDeployStepsRuntimeReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteWithBuildSteps(array $stepTypes, ?array $detected): Site
{
    $server = Server::factory()->ready()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Php,
        'runtime' => 'php',
        'meta' => $detected === null ? [] : ['vm_runtime' => ['detected' => $detected]],
    ]);

    $pipeline = SiteDeployPipeline::create([
        'site_id' => $site->id,
        'name' => 'Default',
        'slug' => 'default',
        'is_default' => true,
        'sort_order' => 1,
    ]);

    foreach ($stepTypes as $i => $type) {
        SiteDeployStep::create([
            'site_id' => $site->id,
            'pipeline_id' => $pipeline->id,
            'step_type' => $type,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'sort_order' => ($i + 1) * 10,
            'timeout_seconds' => 600,
        ]);
    }

    return $site->fresh();
}

test('a node repo replaces the php build steps it was seeded with', function () {
    // The exact failure: site seeded as PHP, Node repo connected, deploy runs
    // `composer install` and dies with "php: command not found".
    $site = siteWithBuildSteps(
        [SiteDeployStep::TYPE_COMPOSER_INSTALL],
        ['language' => 'node', 'framework' => 'nextjs'],
    );

    $note = app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site);

    expect($note)->toContain('node');

    $types = $site->fresh()->deployPipelines()->with('steps')->first()
        ->steps->where('phase', SiteDeployStep::PHASE_BUILD)->pluck('step_type')->all();

    expect($types)->toContain(SiteDeployStep::TYPE_NPM_CI)
        ->and($types)->not->toContain(SiteDeployStep::TYPE_COMPOSER_INSTALL);

    // 'nextjs' from RepositoryRuntimeDetector must map to 'next', which is what
    // RuntimeAwareDeployStepDefaults keys on, or the build step goes missing.
    expect($types)->toContain(SiteDeployStep::TYPE_NPM_RUN);
});

test('it leaves a correct pipeline alone', function () {
    $site = siteWithBuildSteps(
        [SiteDeployStep::TYPE_NPM_CI],
        ['language' => 'node', 'framework' => 'express'],
    );

    expect(app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site))->toBeNull();
});

test('it refuses to rewrite a customised pipeline', function () {
    // A deploy pipeline is user-editable. Throwing away hand-written steps is
    // worse than failing with a clear reason.
    $site = siteWithBuildSteps(
        [SiteDeployStep::TYPE_COMPOSER_INSTALL, SiteDeployStep::TYPE_CUSTOM],
        ['language' => 'node', 'framework' => 'nextjs'],
    );

    $note = app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site);

    expect($note)->toContain('customised');

    $types = $site->fresh()->deployPipelines()->with('steps')->first()
        ->steps->pluck('step_type')->all();

    expect($types)->toContain(SiteDeployStep::TYPE_CUSTOM);
});

test('no detection means no change', function () {
    $site = siteWithBuildSteps([SiteDeployStep::TYPE_COMPOSER_INSTALL], null);

    expect(app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site))->toBeNull();
});

test('a go repo replaces php build steps too', function () {
    // Go / Ruby / Python / Static all emit TYPE_CUSTOM steps, so recognition
    // has to compare the full signature, not just the step type.
    $site = siteWithBuildSteps(
        [SiteDeployStep::TYPE_COMPOSER_INSTALL],
        ['language' => 'go', 'framework' => 'gin'],
    );

    $note = app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site);

    expect($note)->toContain('go');

    $commands = $site->fresh()->deployPipelines()->with('steps')->first()
        ->steps->where('phase', SiteDeployStep::PHASE_BUILD)->pluck('custom_command')->all();

    expect($commands)->toContain('go build -o bin/app ./...');
});

test('a rails repo replaces php build steps', function () {
    $site = siteWithBuildSteps(
        [SiteDeployStep::TYPE_COMPOSER_INSTALL],
        ['language' => 'ruby', 'framework' => 'rails'],
    );

    app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site);

    $commands = $site->fresh()->deployPipelines()->with('steps')->first()
        ->steps->where('phase', SiteDeployStep::PHASE_BUILD)->pluck('custom_command')->all();

    expect($commands)->toContain('bundle install --deployment --without development:test');
});

test('a hand-written custom step is never discarded even when it looks like a default', function () {
    // A custom step whose command is NOT one the defaults emit is a human's.
    $site = siteWithBuildSteps([SiteDeployStep::TYPE_COMPOSER_INSTALL], ['language' => 'node', 'framework' => 'nextjs']);

    $pipeline = $site->deployPipelines()->first();
    $pipeline->steps()->create([
        'site_id' => $site->id,
        'step_type' => SiteDeployStep::TYPE_CUSTOM,
        'custom_command' => './scripts/our-bespoke-build.sh',
        'phase' => SiteDeployStep::PHASE_BUILD,
        'sort_order' => 99,
        'timeout_seconds' => 600,
    ]);

    $note = app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site->fresh());

    expect($note)->toContain('customised');
    expect($site->fresh()->deployPipelines()->with('steps')->first()->steps->pluck('custom_command')->all())
        ->toContain('./scripts/our-bespoke-build.sh');
});

test('a pnpm project installs with pnpm, not npm ci', function () {
    // `npm ci` against a pnpm repo fails outright: "package.json and
    // package-lock.json are not in sync".
    $site = siteWithBuildSteps(
        [SiteDeployStep::TYPE_COMPOSER_INSTALL],
        ['language' => 'node', 'framework' => 'nextjs', 'package_manager' => 'pnpm'],
    );

    app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site);

    $types = $site->fresh()->deployPipelines()->with('steps')->first()
        ->steps->where('phase', SiteDeployStep::PHASE_BUILD)->pluck('step_type')->all();

    expect($types)->toContain(SiteDeployStep::TYPE_PNPM_INSTALL)
        ->and($types)->not->toContain(SiteDeployStep::TYPE_NPM_CI);
});

test('yarn and bun pick their own install steps', function () {
    foreach ([
        'yarn' => SiteDeployStep::TYPE_YARN_INSTALL,
        'bun' => SiteDeployStep::TYPE_BUN_INSTALL,
        'npm' => SiteDeployStep::TYPE_NPM_CI,
    ] as $manager => $expected) {
        $site = siteWithBuildSteps(
            [SiteDeployStep::TYPE_COMPOSER_INSTALL],
            ['language' => 'node', 'framework' => 'express', 'package_manager' => $manager],
        );

        app(SiteDeployStepsRuntimeReconciler::class)->reconcile($site);

        $types = $site->fresh()->deployPipelines()->with('steps')->first()
            ->steps->where('phase', SiteDeployStep::PHASE_BUILD)->pluck('step_type')->all();

        expect($types)->toContain($expected);
    }
});
