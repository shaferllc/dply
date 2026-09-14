<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\QueueActivityHorizonTest;

use App\Jobs\CollectSiteFailedJobsJob;
use App\Livewire\Sites\WorkspaceQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteQueueJobRun;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Server, 2: Site} */
function horizonSite(string $command = 'php artisan horizon'): array
{
    Bus::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'runtime' => 'php']);

    SupervisorProgram::query()->create([
        'server_id' => $server->id,
        'site_id' => $site->id,
        'slug' => 'worker',
        'program_type' => 'queue',
        'command' => $command,
        'directory' => '/home/dply/app',
        'user' => 'dply',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $site->putMeta('horizon', [
        'status' => 'running',
        'processes' => 15,
        'jobs_per_minute' => 4,
        'failed_recent' => 3,
        'collected_at' => now()->toIso8601String(),
        'queue_throughput' => ['default' => [1.0, 4.0, 2.5]],
        'recent_jobs' => [
            ['name' => 'App\\Jobs\\ChargeCard', 'queue' => 'default', 'status' => 'reserved', 'age' => 12.0],
            // Another server's queue: Horizon's list spans every box on its Redis.
            ['name' => 'App\\Jobs\\RunReport', 'queue' => 'reports', 'status' => 'reserved', 'age' => 3.0],
            ['name' => 'App\\Jobs\\MailDigest', 'queue' => 'reports', 'status' => 'completed', 'age' => 5.0],
            ['name' => 'App\\Jobs\\SendReceipt', 'queue' => 'default', 'status' => 'completed', 'age' => 40.0],
            ['name' => 'App\\Jobs\\SyncLedger', 'queue' => 'default', 'status' => 'failed', 'age' => 90.0],
        ],
    ]);

    SiteQueueSnapshot::query()->create([
        'site_id' => $site->id,
        'queue' => 'default',
        'source' => 'horizon',
        'pending' => 0,
        'reserved' => 1,
        'failed_total' => 2,
        'captured_at' => now(),
    ]);

    return [$user, $server, $site->fresh()];
}

test('Running lists the jobs Horizon says a worker is holding', function () {
    [$user, $server, $site] = horizonSite();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('showActivity', 'running')
        ->assertSee('App\\Jobs\\ChargeCard')
        ->assertSee('running for 12 s')
        ->assertDontSee('App\\Jobs\\SendReceipt')
        ->assertDontSee('App\\Jobs\\RunReport');
});

test('History falls back to Horizon’s finished jobs when the agent has recorded nothing', function () {
    [$user, $server, $site] = horizonSite();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('showActivity', 'history')
        ->assertSee('From Horizon’s recent jobs')
        ->assertSee('App\\Jobs\\SendReceipt')
        ->assertSee('App\\Jobs\\SyncLedger')
        ->assertDontSee('App\\Jobs\\ChargeCard')
        ->assertDontSee('App\\Jobs\\MailDigest');
});

test('the page shows one failed number, and a throughput line per Horizon queue', function () {
    [$user, $server, $site] = horizonSite();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        // Horizon's own "recently failed" window disagreed with the badge.
        ->assertDontSee('failed recently')
        ->assertSee('15 processes · 4 jobs/min')
        ->assertSee('Throughput from Horizon, peak 4 jobs/min');
});

test('the Horizon row warns when another app shares this app’s Horizon keys', function () {
    [$user, $server, $site] = horizonSite();
    $site->putMeta('queue_horizon_shared', ['dply-control', 'dply-provision']);

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site->fresh()])
        ->assertSee('it runs dply-control, dply-provision')
        ->assertSee('Set a unique HORIZON_PREFIX');
});

test('History shows Horizon’s finished jobs next to the agent’s runs, not only instead of them', function () {
    // A single canary in History used to hide every job the app itself ran.
    [$user, $server, $site] = horizonSite();
    SiteQueueJobRun::query()->create([
        'site_id' => $site->id,
        'name' => 'App\\Jobs\\CanaryProbe',
        'queue' => 'default',
        'status' => 'processed',
        'source' => 'canary',
        'ran_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('showActivity', 'history')
        ->assertSee('App\\Jobs\\CanaryProbe')
        ->assertSee('App\\Jobs\\SendReceipt');
});

test('a read that never answers stops spinning after 90 seconds and says so', function () {
    [$user, $server, $site] = horizonSite();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('showActivity', 'failed')
        ->assertSee('Reading failed jobs…')
        ->set('reads_started', ['failed' => now()->subSeconds(120)->getTimestamp()])
        ->assertSee('No answer from the server yet')
        ->assertDontSee('Reading failed jobs…');
});

test('a read job answers with the reason when it cannot reach the site, instead of saying nothing', function () {
    [, $server, $site] = horizonSite();
    $server->forceFill(['status' => 'provisioning'])->save();

    (new CollectSiteFailedJobsJob((string) $site->id))->handle(app(ExecuteRemoteTaskOnServer::class));

    expect(CollectSiteFailedJobsJob::cached((string) $site->id)['error'] ?? null)->toContain('isn’t ready');
});

test('a queue the latest sweep no longer reports drops off the page', function () {
    // Sampled an hour ago — before the sweep stopped reading other servers'
    // Horizon queues — and not since. It must not linger for the 24h window.
    [$user, $server, $site] = horizonSite();
    SiteQueueSnapshot::query()->create([
        'site_id' => $site->id,
        'queue' => 'dply-control',
        'source' => 'horizon',
        'pending' => 0,
        'captured_at' => now()->subHour(),
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->assertDontSee('dply-control')
        ->assertSee('default');
});

test('without Horizon, Running says how many are held rather than inventing names', function () {
    [$user, $server, $site] = horizonSite('php artisan queue:work');

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('showActivity', 'running')
        ->assertSee('Naming the job a worker is holding needs Horizon or the queue agent.')
        ->assertSee('1 running')
        ->assertDontSee('App\\Jobs\\ChargeCard');
});
