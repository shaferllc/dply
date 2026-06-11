<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\SyncConsoleRowsTest;

use App\Livewire\Sites\DeployControl;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/** @return array{0: Site, 1: Site} primary + worker on the same repo */
function syncSites(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

    $repo = 'git@github.com:acme/app.git';
    $primary = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'user_id' => $user->id, 'name' => 'app', 'git_repository_url' => $repo]);
    $worker = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'user_id' => $user->id, 'name' => 'app-worker', 'git_repository_url' => $repo]);

    return [$primary, $worker];
}

test('sync rows reflect each launched peer’s live deploy state', function () {
    [$primary, $worker] = syncSites();

    // Worker is mid-build; primary has no deployment yet (queued).
    $worker->deployments()->create([
        'project_id' => $worker->project_id,
        'status' => SiteDeployment::STATUS_RUNNING,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'phase_results' => [
            'clone' => [['step_type' => 'clone', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 4000]],
            'build' => [
                ['step_id' => 's1', 'step_type' => 'composer_install', 'ok' => true, 'skipped' => false, 'output' => 'done', 'duration_ms' => 1000],
                ['step_id' => 's2', 'step_type' => 'npm_ci', 'ok' => false, 'skipped' => false, 'running' => true, 'pending' => false, 'output' => '', 'duration_ms' => 0],
            ],
        ],
    ]);

    $component = new DeployControl;
    $component->site = $primary;
    $component->syncedSiteIds = [(string) $primary->id, (string) $worker->id];

    $rows = collect($component->syncRows())->keyBy('id');

    $workerRow = $rows->get((string) $worker->id);
    expect($workerRow['status'])->toBe('running');
    expect($workerRow['in_progress'])->toBeTrue();
    expect($workerRow['current_phase'])->toBe('Build');
    // clone done out of clone/build/release/activate.
    expect($workerRow['phase_done'])->toBe(1);
    expect($workerRow['phase_total'])->toBe(4);

    $primaryRow = $rows->get((string) $primary->id);
    expect($primaryRow['status'])->toBe('queued');
    expect($primaryRow['in_progress'])->toBeFalse();
    expect($primaryRow['phases'])->toBe([]);
});

test('sync rows report finished when every peer deploy has settled', function () {
    [$primary, $worker] = syncSites();

    foreach ([$primary, $worker] as $s) {
        $s->deployments()->create([
            'project_id' => $s->project_id,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'phase_results' => ['build' => [['step_type' => 'composer_install', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 10]]],
        ]);
    }

    $component = new DeployControl;
    $component->site = $primary;
    $component->syncedSiteIds = [(string) $primary->id, (string) $worker->id];

    $rows = $component->syncRows();
    expect(collect($rows)->every(fn ($r) => $r['in_progress'] === false))->toBeTrue();
    expect(collect($rows)->every(fn ($r) => $r['status'] === 'success'))->toBeTrue();
});

test('a row clears as soon as every phase is done, before the deployment row is finalised', function () {
    [$primary] = syncSites();

    // The worker writes phase_results (all phases done) BEFORE it flips the row
    // to success + sets finished_at. Reproduce that window: status still RUNNING,
    // finished_at null, every phase recorded ok, and an active deploy lock held.
    $primary->deployments()->create([
        'project_id' => $primary->project_id,
        'status' => SiteDeployment::STATUS_RUNNING,
        'finished_at' => null,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'phase_results' => [
            'clone' => [['step_type' => 'clone', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 10]],
            'build' => [['step_type' => 'composer_install', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 10]],
            'release' => [['step_type' => 'artisan_migrate', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 10]],
            'activate' => [['step_type' => 'activate', 'ok' => true, 'skipped' => false, 'output' => 'ok', 'duration_ms' => 10]],
        ],
    ]);

    // Pre-fix, this lock forced status 'starting' + a spinner until its TTL.
    Cache::put('site-deploy-active:'.$primary->id, ['started_at' => now()->toIso8601String(), 'deployment_id' => null], 600);

    $component = new DeployControl;
    $component->site = $primary;
    $component->syncedSiteIds = [(string) $primary->id];

    $row = collect($component->syncRows())->firstWhere('id', (string) $primary->id);

    expect($row['phase_total'])->toBeGreaterThan(0);
    expect($row['phase_done'])->toBe($row['phase_total']);
    expect($row['status'])->not->toBe('starting');
    expect($row['in_progress'])->toBeFalse();
});
