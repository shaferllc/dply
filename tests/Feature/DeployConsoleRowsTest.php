<?php

declare(strict_types=1);

namespace Tests\Feature\DeployConsoleRowsTest;

use App\Livewire\DeployConsoleSidebar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Support\Sites\DeployConsoleRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('new deploy lock outranks a finished latest deployment so the console shows starting', function () {
    [, , $site] = makeDeployConsoleSite();

    $finishedAt = now()->subMinute();
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-finished',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => $finishedAt->copy()->subMinutes(2),
        'finished_at' => $finishedAt,
    ]);
    $deployment->recordPhaseResults('build', [
        ['step_id' => '1', 'command' => 'npm run build', 'ok' => true, 'output' => '', 'duration_ms' => 1000],
    ]);
    $deployment->recordPhaseResults('release', [
        ['step_id' => '2', 'command' => 'activate', 'ok' => true, 'output' => '', 'duration_ms' => 100],
    ]);

    // Fresh kickoff: optimistic lock is set before the worker creates a new row.
    Cache::put('site-deploy-active:'.$site->id, [
        'started_at' => now()->toIso8601String(),
        'deployment_id' => null,
    ], 600);

    $rows = DeployConsoleRows::forSiteIds([(string) $site->id]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['starting_fresh'])->toBeTrue()
        ->and($rows[0]['in_progress'])->toBeTrue()
        ->and($rows[0]['status'])->toBe('starting')
        ->and($rows[0]['phases'])->toBe([])
        ->and($rows[0]['latest'])->toBeNull()
        ->and(DeployConsoleRows::anyInProgress($rows))->toBeTrue();
});

test('finished deployment without a lock is not treated as in progress', function () {
    [, , $site] = makeDeployConsoleSite();

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-done',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(3),
    ]);

    $rows = DeployConsoleRows::forSiteIds([(string) $site->id]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['starting_fresh'])->toBeFalse()
        ->and($rows[0]['in_progress'])->toBeFalse()
        ->and($rows[0]['status'])->toBe(SiteDeployment::STATUS_SUCCESS)
        // Finished successes skip the heavy phase timeline on open.
        ->and($rows[0]['phases'])->toBe([]);
});

test('running deployment still builds a live phase timeline', function () {
    [, , $site] = makeDeployConsoleSite();

    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-running-phases',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now()->subMinute(),
        'finished_at' => null,
    ]);
    $deployment->recordPhaseResults('clone', [
        ['step_id' => '1', 'command' => 'git clone', 'ok' => true, 'output' => '', 'duration_ms' => 200],
    ]);
    $deployment->recordPhaseResults('build', [
        ['step_id' => '2', 'command' => 'composer install', 'ok' => true, 'output' => '', 'duration_ms' => 800, 'running' => true],
    ]);

    $rows = DeployConsoleRows::forSiteIds([(string) $site->id]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['in_progress'])->toBeTrue()
        ->and($rows[0]['phases'])->not->toBeEmpty()
        ->and($rows[0]['phase_total'])->toBeGreaterThan(0);
});

test('deploy console rows expose server branch and commit meta without stale sha on fresh kickoff', function () {
    [, $server, $site] = makeDeployConsoleSite();

    $server->update(['name' => 'prod-web-1', 'ip_address' => '203.0.113.10']);
    $site->update([
        'name' => 'Acme API',
        'git_branch' => 'release/2.4',
        'git_repository_url' => 'git@github.com:acme/api.git',
    ]);

    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-sha',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'git_sha' => 'abcdef0123456789abcdef0123456789abcdef01',
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(3),
    ]);

    $rows = DeployConsoleRows::forSiteIds([(string) $site->id]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['server'])->toBe('prod-web-1')
        ->and($rows[0]['server_ip'])->toBe('203.0.113.10')
        ->and($rows[0]['branch'])->toBe('release/2.4')
        ->and($rows[0]['short_sha'])->toBe('abcdef0')
        ->and($rows[0]['commit_url'])->toBe('https://github.com/acme/api/commit/abcdef0123456789abcdef0123456789abcdef01');

    Cache::put('site-deploy-active:'.$site->id, [
        'started_at' => now()->toIso8601String(),
        'deployment_id' => null,
    ], 600);

    $fresh = DeployConsoleRows::forSiteIds([(string) $site->id]);

    expect($fresh[0]['starting_fresh'])->toBeTrue()
        ->and($fresh[0]['git_sha'])->toBeNull()
        ->and($fresh[0]['short_sha'])->toBeNull()
        ->and($fresh[0]['commit_url'])->toBeNull()
        ->and($fresh[0]['branch'])->toBe('release/2.4')
        ->and($fresh[0]['server'])->toBe('prod-web-1');
});

test('global deploy console focus replaces a finished batch with the new site ids', function () {
    [, , $siteA] = makeDeployConsoleSite();
    [, , $siteB] = makeDeployConsoleSite();

    Livewire::test(DeployConsoleSidebar::class)
        ->set('watchedSiteIds', [(string) $siteA->id])
        ->set('watchingBatch', true)
        ->call('focusSites', [(string) $siteB->id])
        ->assertSet('watchedSiteIds', [(string) $siteB->id])
        ->assertSet('watchingBatch', true)
        ->assertDispatched('dply-deploy-console-open');
});

test('dismiss finished hides from sidebar without deleting deploy history', function () {
    [$user, $server, $site] = makeDeployConsoleSite();
    $org = $user->currentOrganization();

    $finished = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-clear-finished',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(8),
    ]);
    $failed = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-clear-failed',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'started_at' => now()->subMinutes(6),
        'finished_at' => now()->subMinutes(5),
    ]);

    // Same org: in-progress deploy on another site must stay after dismiss.
    $runningSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
    $running = SiteDeployment::query()->create([
        'site_id' => $runningSite->id,
        'project_id' => $runningSite->project_id,
        'idempotency_key' => 'dep-clear-running',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now()->subMinute(),
        'finished_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test(DeployConsoleSidebar::class)
        ->set('watchedSiteIds', [(string) $site->id, (string) $runningSite->id])
        ->set('watchingBatch', true)
        ->call('openClearFinishedConfirm')
        ->assertSet('showConfirmActionModal', true)
        ->call('confirmActionModal')
        ->assertSet('showConfirmActionModal', false)
        ->assertSet('watchingBatch', false)
        ->assertDispatched('notify', type: 'success')
        ->assertSet('watchedSiteIds', [(string) $runningSite->id]);

    // History stays in the database.
    expect(SiteDeployment::query()->find($finished->id))->not->toBeNull()
        ->and(SiteDeployment::query()->find($failed->id))->not->toBeNull()
        ->and(SiteDeployment::query()->find($running->id))->not->toBeNull();

    expect(session(DeployConsoleSidebar::DISMISSED_BEFORE_SESSION_PREFIX.(string) $org->id))->not->toBeEmpty();
});

test('dismiss finished does not affect other organization sidebar state', function () {
    [$user, , $site] = makeDeployConsoleSite();

    $otherOrg = Organization::factory()->create();
    $otherUser = User::factory()->create();
    $otherOrg->users()->attach($otherUser->id, ['role' => 'owner']);
    $otherServer = Server::factory()->ready()->create([
        'user_id' => $otherUser->id,
        'organization_id' => $otherOrg->id,
    ]);
    $otherSite = Site::factory()->create([
        'server_id' => $otherServer->id,
        'user_id' => $otherUser->id,
        'organization_id' => $otherOrg->id,
        'runtime' => 'php',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $own = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-own-finished',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subMinutes(4),
        'finished_at' => now()->subMinutes(3),
    ]);
    $other = SiteDeployment::query()->create([
        'site_id' => $otherSite->id,
        'project_id' => $otherSite->project_id,
        'idempotency_key' => 'dep-other-finished',
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subMinutes(4),
        'finished_at' => now()->subMinutes(3),
    ]);

    Livewire::actingAs($user)
        ->test(DeployConsoleSidebar::class)
        ->set('watchedSiteIds', [(string) $site->id])
        ->call('clearFinishedDeployments')
        ->assertDispatched('notify', type: 'success');

    expect(SiteDeployment::query()->find($own->id))->not->toBeNull()
        ->and(SiteDeployment::query()->find($other->id))->not->toBeNull();

    $ownOrgKey = DeployConsoleSidebar::DISMISSED_BEFORE_SESSION_PREFIX.(string) $user->currentOrganization()->id;
    $otherOrgKey = DeployConsoleSidebar::DISMISSED_BEFORE_SESSION_PREFIX.(string) $otherOrg->id;
    expect(session($ownOrgKey))->not->toBeEmpty()
        ->and(session($otherOrgKey))->toBeNull();
});

test('forSiteIds avoids per-site deployment queries', function () {
    [, , $siteA] = makeDeployConsoleSite();
    [, , $siteB] = makeDeployConsoleSite();

    foreach ([$siteA, $siteB] as $site) {
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'dep-bulk-'.$site->id,
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(3),
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $rows = DeployConsoleRows::forSiteIds([(string) $siteA->id, (string) $siteB->id]);

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->toHaveCount(2);

    // Must not issue one deployments() query per site (the old N+1).
    $perSiteLatest = $queries->filter(
        fn (array $q): bool => str_contains($q['query'], 'site_deployments')
            && str_contains($q['query'], 'limit 1'),
    );
    expect($perSiteLatest)->toHaveCount(0);

    // Finished successes should not load deploy_steps / bindings / hooks.
    $timelineRelationQueries = $queries->filter(
        fn (array $q): bool => str_contains($q['query'], 'site_deploy_steps')
            || str_contains($q['query'], 'site_bindings')
            || str_contains($q['query'], 'site_deploy_hooks'),
    );
    expect($timelineRelationQueries)->toHaveCount(0);
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeDeployConsoleSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site->fresh()];
}
