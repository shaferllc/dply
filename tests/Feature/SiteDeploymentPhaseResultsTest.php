<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDeploymentPhaseResultsTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('record phase results persists under phase key', function () {
    $deployment = makeDeployment();

    $deployment->recordPhaseResults('build', [
        ['step_id' => '1', 'step_type' => 'composer_install', 'ok' => true, 'output' => 'ran', 'duration_ms' => 1234],
    ]);
    $deployment->recordPhaseResults('release', [
        ['step_id' => '2', 'step_type' => 'artisan_migrate', 'ok' => true, 'output' => 'ok', 'duration_ms' => 5678],
    ]);

    $deployment->refresh();
    expect($deployment->phase_results['build'][0]['step_type'])->toBe('composer_install');
    expect($deployment->phase_results['release'][0]['step_type'])->toBe('artisan_migrate');
});
test('record phase results replaces prior phase data', function () {
    $deployment = makeDeployment();
    $deployment->recordPhaseResults('build', [
        ['step_id' => '1', 'ok' => false, 'output' => 'fail', 'duration_ms' => 100],
    ]);
    $deployment->recordPhaseResults('build', [
        ['step_id' => '2', 'ok' => true, 'output' => 'success', 'duration_ms' => 50],
    ]);

    $deployment->refresh();
    expect($deployment->phase_results['build'])->toHaveCount(1);
    expect($deployment->phase_results['build'][0]['ok'])->toBeTrue();
});
test('phases all ok returns true when every step passed', function () {
    $deployment = makeDeployment();
    $deployment->recordPhaseResults('build', [
        ['step_id' => '1', 'ok' => true, 'duration_ms' => 100],
        ['step_id' => '2', 'ok' => true, 'duration_ms' => 50, 'skipped' => true],
    ]);
    $deployment->recordPhaseResults('release', [
        ['step_id' => '3', 'ok' => true, 'duration_ms' => 200],
    ]);

    expect($deployment->phasesAllOk())->toBeTrue();
});
test('phases all ok returns false when any step failed', function () {
    $deployment = makeDeployment();
    $deployment->recordPhaseResults('build', [
        ['step_id' => '1', 'ok' => true, 'duration_ms' => 100],
    ]);
    $deployment->recordPhaseResults('release', [
        ['step_id' => '2', 'ok' => false, 'output' => 'boom', 'duration_ms' => 80],
    ]);

    expect($deployment->phasesAllOk())->toBeFalse();
});
test('phases all ok is false when no phases recorded', function () {
    $deployment = makeDeployment();

    // Nothing recorded yet — treat as not-ok so callers can
    // distinguish "haven't run" from "all phases passed".
    expect($deployment->phasesAllOk())->toBeFalse();
});
test('phase total duration sums all steps', function () {
    $deployment = makeDeployment();
    $deployment->recordPhaseResults('build', [
        ['step_id' => '1', 'ok' => true, 'duration_ms' => 1000],
        ['step_id' => '2', 'ok' => true, 'duration_ms' => 2500],
    ]);
    $deployment->recordPhaseResults('swap', [
        ['step_id' => 'swap', 'ok' => true, 'duration_ms' => 12],
    ]);
    $deployment->recordPhaseResults('release', [
        ['step_id' => '3', 'ok' => true, 'duration_ms' => 800],
    ]);
    $deployment->recordPhaseResults('restart', [
        ['step_id' => 'restart', 'ok' => true, 'duration_ms' => 5],
    ]);

    expect($deployment->phaseTotalDurationMs())->toBe(1000 + 2500 + 12 + 800 + 5);
});
test('phase total duration is zero when unset', function () {
    expect(makeDeployment()->phaseTotalDurationMs())->toBe(0);
});
test('phase results round trips through db as json', function () {
    $deployment = makeDeployment();
    $deployment->recordPhaseResults('build', [
        ['step_id' => '1', 'command' => 'composer install', 'ok' => true, 'output' => 'foo', 'duration_ms' => 100],
    ]);

    $reloaded = SiteDeployment::query()->whereKey($deployment->id)->firstOrFail();
    expect($reloaded->phase_results)->toBeArray();
    expect($reloaded->phase_results['build'][0]['command'])->toBe('composer install');
});
function makeDeployment(): SiteDeployment
{
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    // Site::created auto-creates a Project; reuse it for the
    // deployment so the not-null FK is satisfied.
    $site->refresh();

    return SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-'.uniqid(),
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
    ]);
}
