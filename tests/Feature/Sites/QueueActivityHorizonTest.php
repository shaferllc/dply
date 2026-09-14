<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\QueueActivityHorizonTest;

use App\Livewire\Sites\WorkspaceQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Models\User;
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
        ->assertDontSee('App\\Jobs\\SendReceipt');
});

test('History falls back to Horizon’s finished jobs when the agent has recorded nothing', function () {
    [$user, $server, $site] = horizonSite();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('showActivity', 'history')
        ->assertSee('From Horizon’s recent jobs')
        ->assertSee('App\\Jobs\\SendReceipt')
        ->assertSee('App\\Jobs\\SyncLedger')
        ->assertDontSee('App\\Jobs\\ChargeCard');
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

test('without Horizon, Running says how many are held rather than inventing names', function () {
    [$user, $server, $site] = horizonSite('php artisan queue:work');

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('showActivity', 'running')
        ->assertSee('Naming the job a worker is holding needs Horizon or the queue agent.')
        ->assertSee('1 running')
        ->assertDontSee('App\\Jobs\\ChargeCard');
});
