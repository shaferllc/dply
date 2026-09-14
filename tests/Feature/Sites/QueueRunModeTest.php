<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\QueueRunModeTest;

use App\Jobs\SetUpSiteQueueingJob;
use App\Livewire\Sites\WorkspaceQueue;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Modules\Queue\Jobs\BuildFleetImageJob;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\FleetImageBuilder;
use App\Modules\Queue\Services\FleetReconciler;
use App\Support\Sites\QueueWorkerPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Server, 2: Site, 3: QueueNamespace} */
function siteOnDplyQueue(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'runtime' => 'php']);

    $namespace = (new QueueNamespace)->forceFill([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'name' => 'edge',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
    $namespace->save();
    $site->forceFill(['meta' => ['managed_queue' => ['namespace_id' => (string) $namespace->id]]])->save();

    return [$user, $server, $site->fresh(), $namespace];
}

function fleetFor(QueueNamespace $namespace, string $status, string $image = ''): ManagedQueueFleet
{
    return ManagedQueueFleet::query()->create([
        'namespace_id' => $namespace->id,
        'organization_id' => $namespace->organization_id,
        'queue' => 'default',
        'class' => ManagedQueueFleet::CLASS_FLEX,
        'status' => $status,
        'image' => $image,
        'memory_mib' => 256,
        'min_workers' => 0,
        'max_workers' => 3,
    ]);
}

function fleetHost(): Server
{
    return Server::factory()->create(['meta' => ['queue_fleet_host' => ['enabled' => true, 'capacity_mib' => 3072]]]);
}

test('the picker shows where jobs run today', function () {
    [$user, $server, $site, $namespace] = siteOnDplyQueue();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->assertSee('Run jobs on')
        ->assertSee('Coming soon')
        ->assertOk();

    expect(Livewire::actingAs($user)->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])->instance()->queueRunMode())->toBe('dply');

    fleetFor($namespace, ManagedQueueFleet::STATUS_ACTIVE);
    expect(Livewire::actingAs($user)->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])->instance()->queueRunMode())->toBe('dply_servers');
});

test('choosing this server leaves dply, and the picker says so', function () {
    Bus::fake([SetUpSiteQueueingJob::class]);
    [$user, $server, $site, $namespace] = siteOnDplyQueue();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('chooseRunMode', 'own');

    // The namespace is kept on purpose — it must not read as still connected.
    expect(QueueNamespace::query()->whereKey($namespace->id)->exists())->toBeTrue()
        ->and($component->instance()->queueRunMode())->toBe('own');
    Bus::assertDispatched(SetUpSiteQueueingJob::class);

    // A second click is a no-op, not a second disconnect of nothing.
    Bus::fake([SetUpSiteQueueingJob::class]);
    $component->call('chooseRunMode', 'own');
    Bus::assertNotDispatched(SetUpSiteQueueingJob::class);
});

test('coming back to the dply queue rejoins the kept namespace', function () {
    Bus::fake([SetUpSiteQueueingJob::class]);
    [$user, $server, $site, $namespace] = siteOnDplyQueue();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('chooseRunMode', 'own')
        ->call('chooseRunMode', 'dply');

    expect(QueueNamespace::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(data_get($site->fresh()->meta, 'managed_queue.namespace_id'))->toBe((string) $namespace->id)
        ->and($component->instance()->queueRunMode())->toBe('dply');
});

test('choosing dply servers builds the image first and leaves this server’s workers running', function () {
    Bus::fake([BuildFleetImageJob::class, SetUpSiteQueueingJob::class]);
    [$user, $server, $site, $namespace] = siteOnDplyQueue();
    fleetHost();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('chooseRunMode', 'dply_servers');

    $fleet = ManagedQueueFleet::query()->where('namespace_id', $namespace->id)->sole();
    expect($fleet->status)->toBe(ManagedQueueFleet::STATUS_ACTIVE)->and($fleet->queue)->toBe('default');
    Bus::assertDispatched(BuildFleetImageJob::class, fn ($job): bool => $job->fleetId === (string) $fleet->id);
    Bus::assertNotDispatched(SetUpSiteQueueingJob::class);
});

test('with an image already built, choosing dply servers hands the queue over now', function () {
    Bus::fake([BuildFleetImageJob::class, SetUpSiteQueueingJob::class]);
    [$user, $server, $site, $namespace] = siteOnDplyQueue();
    fleetHost();
    $fleet = fleetFor($namespace, ManagedQueueFleet::STATUS_PAUSED, 'app:abc');

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('chooseRunMode', 'dply_servers');

    expect($fleet->fresh()->status)->toBe(ManagedQueueFleet::STATUS_ACTIVE);
    Bus::assertNotDispatched(BuildFleetImageJob::class);
    Bus::assertDispatched(SetUpSiteQueueingJob::class, fn ($job): bool => $job->driver === 'dply');
});

test('going back to this server’s workers pauses dply’s servers', function () {
    Bus::fake([SetUpSiteQueueingJob::class]);
    [$user, $server, $site, $namespace] = siteOnDplyQueue();
    $fleet = fleetFor($namespace, ManagedQueueFleet::STATUS_ACTIVE, 'app:abc');

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('chooseRunMode', 'dply');

    expect($fleet->fresh()->status)->toBe(ManagedQueueFleet::STATUS_PAUSED);
    Bus::assertDispatched(SetUpSiteQueueingJob::class, fn ($job): bool => $job->driver === 'dply');
});

test('dply servers and functions cannot be chosen when they are not there', function () {
    Bus::fake([BuildFleetImageJob::class, SetUpSiteQueueingJob::class]);
    [$user, $server, $site] = siteOnDplyQueue();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->call('chooseRunMode', 'dply_servers')
        ->call('chooseRunMode', 'functions');

    expect(ManagedQueueFleet::query()->count())->toBe(0);
    Bus::assertNotDispatched(BuildFleetImageJob::class);
    Bus::assertNotDispatched(SetUpSiteQueueingJob::class);
});

test('once dply servers take the jobs, this server’s dply workers stop — but not before', function () {
    [, , $site, $namespace] = siteOnDplyQueue();
    SupervisorProgram::query()->create([
        'server_id' => $site->server_id, 'site_id' => $site->id, 'slug' => 'app-queue-default',
        'program_type' => 'queue', 'command' => "php artisan queue:work --queue='default'",
        'directory' => '/home/dply/app', 'user' => 'dply', 'numprocs' => 1, 'is_active' => true,
    ]);

    // Still building: nothing on this server stops yet.
    fleetFor($namespace, ManagedQueueFleet::STATUS_ACTIVE);
    expect(QueueWorkerPlan::for($site, 'dply')->stop)->toBeEmpty();

    ManagedQueueFleet::query()->update(['image' => 'app:abc']);
    $plan = QueueWorkerPlan::for($site, 'dply');
    expect($plan->stop->pluck('slug')->all())->toBe(['app-queue-default'])
        ->and($plan->createDefault)->toBeFalse();
});

test('a fleet’s first image hands the queue to dply’s servers', function () {
    Bus::fake([SetUpSiteQueueingJob::class]);
    [, , $site, $namespace] = siteOnDplyQueue();
    $fleet = fleetFor($namespace, ManagedQueueFleet::STATUS_ACTIVE);

    $builder = \Mockery::mock(FleetImageBuilder::class);
    $builder->shouldReceive('build')->andReturn('app:abc');
    $reconciler = \Mockery::mock(FleetReconciler::class);
    $reconciler->shouldNotReceive('roll');

    (new BuildFleetImageJob((string) $fleet->id))->handle($builder, $reconciler);

    expect($fleet->fresh()->image)->toBe('app:abc')
        ->and(ConsoleAction::query()->where('subject_id', $site->id)->where('kind', 'queue_setup')->exists())->toBeTrue();
    Bus::assertDispatched(SetUpSiteQueueingJob::class, fn ($job): bool => $job->driver === 'dply' && $job->siteId === (string) $site->id);
});
