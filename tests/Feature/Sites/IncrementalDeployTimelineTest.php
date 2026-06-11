<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\IncrementalDeployTimelineTest;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\Sites\SiteDeployTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runningDeployment(Site $site, array $phaseResults): SiteDeployment
{
    $d = new SiteDeployment(['status' => SiteDeployment::STATUS_RUNNING]);
    $d->site_id = $site->id;
    $d->phase_results = $phaseResults;

    return $d;
}

/** @return array<string, array<string, mixed>> phases keyed by their canonical key */
function phasesByKey(Site $site, SiteDeployment $deployment): array
{
    return collect(SiteDeployTimeline::forDeployment($site, $deployment))
        ->keyBy('key')
        ->all();
}

test('a phase with a running step is reported running, with completed/running/queued steps', function () {
    $site = Site::factory()->create();

    $deployment = runningDeployment($site, [
        'clone' => [
            ['step_type' => 'clone', 'ok' => true, 'skipped' => false, 'output' => 'cloned', 'duration_ms' => 4500],
        ],
        'build' => [
            ['step_id' => 's1', 'step_type' => 'composer_install', 'ok' => true, 'skipped' => false, 'output' => 'composer done', 'duration_ms' => 1200],
            ['step_id' => 's2', 'step_type' => 'npm_ci', 'ok' => false, 'skipped' => false, 'running' => true, 'pending' => false, 'output' => '', 'duration_ms' => 0],
            ['step_id' => 's3', 'step_type' => 'npm_run_build', 'ok' => false, 'skipped' => false, 'pending' => true, 'output' => '', 'duration_ms' => 0],
        ],
    ]);

    $phases = phasesByKey($site, $deployment);

    expect($phases['clone']['status'])->toBe('success');
    expect($phases['build']['status'])->toBe('running');
    // Detection is by the running flag, not "first unrecorded phase" — release
    // must NOT be mistaken for the running phase even though build is recorded.
    expect($phases['release']['status'])->toBe('pending');
    expect($phases['activate']['status'])->toBe('pending');

    $build = collect($phases['build']['steps']);
    expect($build->firstWhere('step_type', 'composer_install')['ok'])->toBeTrue();

    $npm = $build->firstWhere('step_type', 'npm_ci');
    expect($npm['running'])->toBeTrue();
    expect($npm['glyph'])->toBe('⟳');

    $runBuild = $build->firstWhere('step_type', 'npm_run_build');
    expect($runBuild['pending'])->toBeTrue();
    expect($runBuild['running'])->toBeFalse();
});

test('a recorded phase with only queued steps (between steps) still reads running', function () {
    $site = Site::factory()->create();

    $deployment = runningDeployment($site, [
        'build' => [
            ['step_id' => 's1', 'step_type' => 'composer_install', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 100],
            ['step_id' => 's2', 'step_type' => 'npm_ci', 'ok' => false, 'skipped' => false, 'pending' => true, 'output' => '', 'duration_ms' => 0],
        ],
    ]);

    expect(phasesByKey($site, $deployment)['build']['status'])->toBe('running');
});

test('the settled clean record (all ok, no flags) reads success', function () {
    $site = Site::factory()->create();

    $deployment = new SiteDeployment(['status' => SiteDeployment::STATUS_SUCCESS]);
    $deployment->site_id = $site->id;
    $deployment->phase_results = [
        'build' => [
            ['step_id' => 's1', 'step_type' => 'composer_install', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 100],
            ['step_id' => 's2', 'step_type' => 'npm_ci', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 200],
        ],
    ];

    expect(phasesByKey($site, $deployment)['build']['status'])->toBe('success');
});

test('a failed step still reports the phase failed despite a trailing queued step', function () {
    $site = Site::factory()->create();

    $deployment = runningDeployment($site, [
        'build' => [
            ['step_id' => 's1', 'step_type' => 'composer_install', 'ok' => false, 'skipped' => false, 'output' => 'boom', 'duration_ms' => 100],
            ['step_id' => 's2', 'step_type' => 'npm_ci', 'ok' => false, 'skipped' => false, 'pending' => true, 'output' => '', 'duration_ms' => 0],
        ],
    ]);

    expect(phasesByKey($site, $deployment)['build']['status'])->toBe('failed');
});

test('the Restart phase is appended (after Activate) when the site has restart steps', function () {
    $site = \App\Models\Site::factory()->create();
    $site->deploySteps()->create([
        'step_type' => 'artisan_horizon_terminate',
        'phase' => 'restart',
        'sort_order' => 0,
    ]);

    $keys = collect(SiteDeployTimeline::forDeployment($site->fresh(), null))->pluck('key')->all();

    expect($keys)->toBe(['clone', 'build', 'release', 'activate', 'restart']);
});

test('the Restart phase is omitted when there are no restart steps or recorded restart', function () {
    $site = \App\Models\Site::factory()->create();

    $keys = collect(SiteDeployTimeline::forDeployment($site, null))->pluck('key')->all();

    expect($keys)->toBe(['clone', 'build', 'release', 'activate']);
});

test('a recorded restart phase surfaces even without configured restart steps', function () {
    $site = \App\Models\Site::factory()->create();

    $deployment = runningDeployment($site, [
        'restart' => [['step_type' => 'restart', 'ok' => true, 'skipped' => false, 'output' => 'FPM reloaded', 'duration_ms' => 50]],
    ]);

    $keys = collect(SiteDeployTimeline::forDeployment($site, $deployment))->pluck('key')->all();
    expect($keys)->toContain('restart');
});
