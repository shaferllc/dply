<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\SetUpSiteQueueingJobConsoleLifecycleTest;

use App\Jobs\SetUpSiteQueueingJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\Sites\SiteEnvPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

function seedQueueSetupRun(Site $site): ConsoleAction
{
    return ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'queue_setup',
        'status' => ConsoleAction::STATUS_QUEUED,
        'label' => 'Switching to redis',
        'output' => ['v' => 1, 'lines' => []],
    ]);
}

/**
 * The bug this covers: the job streamed every step into the console and
 * returned, but never moved the row off `queued` — so 45 seconds later the
 * banner declared "no queue worker picked up this task" about a setup that
 * had already succeeded.
 */
it('marks the console run completed when the switch succeeds', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $run = seedQueueSetupRun($site);

    app()->instance(SiteEnvPusher::class, \Mockery::mock(SiteEnvPusher::class)->shouldIgnoreMissing());

    $exec = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('', 0));

    $provisioner = \Mockery::mock(SupervisorProvisioner::class);
    $provisioner->shouldReceive('syncProgram')->andReturn('ok');

    app()->call([new SetUpSiteQueueingJob((string) $run->id, (string) $site->id, 'redis'), 'handle'], [
        'exec' => $exec,
        'provisioner' => $provisioner,
    ]);

    $run->refresh();

    expect($run->status)->toBe(ConsoleAction::STATUS_COMPLETED)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->isQueuedStalled())->toBeFalse();
});

it('marks the console run failed when a step breaks', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $run = seedQueueSetupRun($site);

    app()->instance(SiteEnvPusher::class, \Mockery::mock(SiteEnvPusher::class)->shouldIgnoreMissing());

    $exec = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andThrow(new \RuntimeException('ssh died'));

    app()->call([new SetUpSiteQueueingJob((string) $run->id, (string) $site->id, 'redis'), 'handle'], [
        'exec' => $exec,
        'provisioner' => \Mockery::mock(SupervisorProvisioner::class),
    ]);

    $run->refresh();

    expect($run->status)->toBe(ConsoleAction::STATUS_FAILED)
        ->and($run->error)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});
