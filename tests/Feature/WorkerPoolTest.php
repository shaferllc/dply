<?php

use App\Enums\ServerProvider;
use App\Jobs\DrainAndDestroyWorkerJob;
use App\Jobs\ReconcileWorkerPoolJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Models\WorkerPool;
use App\Services\WorkerPools\PoolEnvTransformer;
use App\Services\WorkerPools\WorkerPoolManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Organization} */
function wpActor(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}

function wpWorker(Organization $org, array $attrs = []): Server
{
    return Server::factory()->create(array_merge([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'provider' => ServerProvider::Hetzner,
        'region' => 'fsn1',
        'size' => 'cx22',
        'meta' => ['server_role' => 'worker'],
    ], $attrs));
}

it('creates a pool from a worker and marks it primary', function () {
    [$user, $org] = wpActor();
    $worker = wpWorker($org);

    $pool = app(WorkerPoolManager::class)->createPool($user, $worker, 'My pool');

    expect($pool->primary_server_id)->toBe($worker->id)
        ->and($pool->desired_count)->toBe(1);
    expect($worker->fresh()->pool_role)->toBe(WorkerPool::ROLE_PRIMARY);
    expect($worker->fresh()->worker_pool_id)->toBe($pool->id);
});

it('refuses to create a pool from a non-worker server', function () {
    [$user, $org] = wpActor();
    $app = wpWorker($org, ['meta' => ['server_role' => 'application']]);

    expect(fn () => app(WorkerPoolManager::class)->createPool($user, $app, 'x'))
        ->toThrow(RuntimeException::class);
});

it('rejects a desired count over max_size and accepts a valid one', function () {
    Queue::fake();
    [$user, $org] = wpActor();
    $pool = app(WorkerPoolManager::class)->createPool($user, wpWorker($org), 'p');

    expect(fn () => app(WorkerPoolManager::class)->setDesiredCount($pool, 999))
        ->toThrow(RuntimeException::class);

    app(WorkerPoolManager::class)->setDesiredCount($pool, 3);
    expect($pool->fresh()->desired_count)->toBe(3);
    Queue::assertPushed(ReconcileWorkerPoolJob::class);
});

it('promotes a replica and demotes the old primary (single primary)', function () {
    [$user, $org] = wpActor();
    $manager = app(WorkerPoolManager::class);
    $primary = wpWorker($org);
    $pool = $manager->createPool($user, $primary, 'p');

    $replica = wpWorker($org, [
        'worker_pool_id' => $pool->id,
        'pool_role' => WorkerPool::ROLE_REPLICA,
    ]);

    $manager->promote($pool->fresh(), $replica);

    expect($replica->fresh()->pool_role)->toBe(WorkerPool::ROLE_PRIMARY);
    expect($primary->fresh()->pool_role)->toBe(WorkerPool::ROLE_REPLICA);
    expect($pool->fresh()->primary_server_id)->toBe($replica->id);
    // Exactly one primary in the pool.
    expect(Server::query()->where('worker_pool_id', $pool->id)->where('pool_role', WorkerPool::ROLE_PRIMARY)->count())->toBe(1);
});

it('refuses to remove the primary and drains a replica', function () {
    Queue::fake();
    [$user, $org] = wpActor();
    $manager = app(WorkerPoolManager::class);
    $primary = wpWorker($org);
    $pool = $manager->createPool($user, $primary, 'p');
    $replica = wpWorker($org, ['worker_pool_id' => $pool->id, 'pool_role' => WorkerPool::ROLE_REPLICA]);

    expect(fn () => $manager->removeMember($pool, $primary->fresh()))
        ->toThrow(RuntimeException::class);

    $manager->removeMember($pool, $replica);
    expect($replica->fresh()->meta['pool']['state'] ?? null)->toBe(WorkerPool::MEMBER_DRAINING);
    Queue::assertPushed(DrainAndDestroyWorkerJob::class, fn ($job) => $job->serverId === $replica->id);
});

it('rewrites private backend hosts to public for a cross-region clone and plans exposure', function () {
    [, $org] = wpActor();
    $backend = wpWorker($org, [
        'meta' => ['server_role' => 'redis'],
        'private_ip_address' => '10.0.0.5',
        'ip_address' => '203.0.113.9',
    ]);
    $clone = wpWorker($org, ['ip_address' => '198.51.100.7']);

    $env = "REDIS_HOST=10.0.0.5\nREDIS_PORT=6379\nAPP_ENV=production\n";
    $result = app(PoolEnvTransformer::class)->rewriteForCrossRegion($env, $clone);

    expect($result['env'])->toContain('REDIS_HOST=203.0.113.9')
        ->and($result['env'])->not->toContain('10.0.0.5');
    expect($result['exposures'])->toHaveCount(1);
    expect($result['exposures'][0]['server_id'])->toBe($backend->id)
        ->and($result['exposures'][0]['ports'])->toContain(6379);
});

it('leaves env untouched when no private backend is referenced', function () {
    [, $org] = wpActor();
    $clone = wpWorker($org, ['ip_address' => '198.51.100.7']);

    $env = "APP_ENV=production\nQUEUE_CONNECTION=database\n";
    $result = app(PoolEnvTransformer::class)->rewriteForCrossRegion($env, $clone);

    expect($result['env'])->toBe($env)
        ->and($result['exposures'])->toBe([]);
});
