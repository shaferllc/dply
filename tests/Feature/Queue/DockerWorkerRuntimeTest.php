<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\DockerWorkerRuntimeTest;

use App\Models\Server;
use App\Modules\Queue\Services\Runtimes\DockerWorkerRuntime;
use App\Modules\Queue\Services\Runtimes\FleetHostAllocator;
use App\Modules\Queue\Support\WorkerHandle;
use App\Modules\Queue\Support\WorkerSpec;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->host = Server::factory()->create([
        'meta' => ['queue_fleet_host' => ['enabled' => true, 'capacity_mib' => 8192]],
    ]);

    $this->remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $this->runtime = new DockerWorkerRuntime($this->remote, app(FleetHostAllocator::class));
});

function spec(array $overrides = []): WorkerSpec
{
    return new WorkerSpec(
        fleetId: $overrides['fleetId'] ?? 'fleet-1',
        queue: $overrides['queue'] ?? 'default',
        image: $overrides['image'] ?? 'registry.dply.test/app:v3',
        memoryMib: $overrides['memoryMib'] ?? 1024,
        graceSeconds: $overrides['graceSeconds'] ?? 90,
        env: $overrides['env'] ?? ['DPLY_QUEUE_URL' => 'https://dply.test/api/queue/v1/ns', 'DPLY_QUEUE_SECRET' => 's3cret'],
    );
}

function captureScript(object $test, int $exitCode = 0): callable
{
    $captured = new \stdClass;
    $captured->script = '';

    $test->remote->shouldReceive('runInlineBash')
        ->andReturnUsing(function ($server, $name, $script, $timeout = null) use ($captured, $exitCode) {
            $captured->script = $script;

            return new ProcessOutput('', $exitCode);
        });

    return fn () => $captured->script;
}

/** Customer code on dply's machines: the container must be caged at start. */
test('the container is capability-stripped, memory-capped and pid-limited', function () {
    $script = captureScript($this);

    $this->runtime->start(spec());

    expect($script())
        ->toContain('--cap-drop ALL')
        ->toContain('--security-opt no-new-privileges')
        ->toContain('--pids-limit 512')
        ->toContain('--memory 1024m')
        // Without memory-swap the cap is advisory: the container swaps instead
        // of dying, and a leak becomes a slow worker rather than a restart.
        ->toContain('--memory-swap 1024m')
        ->toContain('--network bridge')
        ->not->toContain('--network host')
        ->not->toContain('--privileged');
});

test('scratch space is tmpfs, sized at half the memory, and non-executable', function () {
    $script = captureScript($this);

    $this->runtime->start(spec(['memoryMib' => 2048]));

    expect($script())->toContain('--tmpfs /tmp:rw,noexec,nosuid,size=1024m');
});

test('cpu scales with the memory size', function () {
    // One capture, read between starts: a second shouldReceive() would never
    // be reached, because the first expectation already matches the call.
    $script = captureScript($this);

    $this->runtime->start(spec(['memoryMib' => 4096]));
    expect($script())->toContain('--cpus 4.00');

    $this->runtime->start(spec(['memoryMib' => 256]));
    expect($script())->toContain('--cpus 0.25');
});

test('the worker runs queue:work against its own queue on the dply connection', function () {
    $script = captureScript($this);

    $this->runtime->start(spec(['queue' => 'invoices']));

    expect($script())
        ->toContain("queue:work dply --queue='invoices'")
        ->toContain("'registry.dply.test/app:v3'")
        ->toContain("DPLY_QUEUE_SECRET=s3cret");
});

test('the handle carries placement so a stop knows which machine to ask', function () {
    captureScript($this);

    $handle = $this->runtime->start(spec());

    expect($handle->hostServerId)->toBe($this->host->id)
        ->and($handle->ref)->toStartWith($this->host->id.':dply-qw-')
        ->and($handle->runtime)->toBe('docker');
});

test('a non-zero docker run is a failure, not a started worker', function () {
    captureScript($this, exitCode: 125);

    expect(fn () => $this->runtime->start(spec()))
        ->toThrow(RuntimeException::class, 'docker run failed');
});

/** The grace period is the compute class's promise; stop must honour it. */
test('stop sends the grace period to docker and outlives it', function () {
    $captured = new \stdClass;

    $this->remote->shouldReceive('runInlineBash')
        ->once()
        ->andReturnUsing(function ($server, $name, $script, $timeout = null) use ($captured) {
            $captured->script = $script;
            $captured->timeout = $timeout;

            return new ProcessOutput('', 0);
        });

    $this->runtime->stop(new WorkerHandle($this->host->id.':dply-qw-abc', 'docker'), 3600);

    expect($captured->script)->toContain('docker stop -t 3600')
        ->and($captured->timeout)->toBeGreaterThan(3600);
});

test('liveness reads docker inspect, and anything unclear counts as gone', function () {
    $this->remote->shouldReceive('runInlineBash')->once()->andReturn(new ProcessOutput('true', 0));
    expect($this->runtime->isAlive(new WorkerHandle($this->host->id.':c', 'docker')))->toBeTrue();

    $this->remote->shouldReceive('runInlineBash')->once()->andReturn(new ProcessOutput('missing', 0));
    expect($this->runtime->isAlive(new WorkerHandle($this->host->id.':c', 'docker')))->toBeFalse();

    $this->remote->shouldReceive('runInlineBash')->once()->andReturn(new ProcessOutput('ssh: connect failed', 255));
    expect($this->runtime->isAlive(new WorkerHandle($this->host->id.':c', 'docker')))->toBeFalse();
});

test('a handle whose host is gone is neither alive nor stoppable', function () {
    $this->remote->shouldReceive('runInlineBash')->never();

    $handle = new WorkerHandle('01JQZZZZZZZZZZZZZZZZZZZZZZ:dply-qw-abc', 'docker');

    expect($this->runtime->isAlive($handle))->toBeFalse();
    $this->runtime->stop($handle, 90);
});
