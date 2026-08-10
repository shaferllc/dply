<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessQueueBackendTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Jobs\ServerlessQueueSlotJob;
use App\Modules\Serverless\Services\ServerlessQueueBackend;
use App\Modules\Serverless\Services\ServerlessQueuePump;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

const REDIS_ENV = "REDIS_HOST=redis.example\nREDIS_PORT=25061\nREDIS_PASSWORD=hunter2\n";

function backendSite(string $env, array $cache = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'env_file_content' => $env,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => array_filter([
                'action_name' => 'demo',
                'queue' => ['enabled' => true],
                'cache' => $cache === [] ? null : $cache,
            ]),
        ],
    ]);
}

function backend(): ServerlessQueueBackend
{
    return app(ServerlessQueueBackend::class);
}

beforeEach(function () {
    Cache::flush();
});

test('redis is shareable', function () {
    $state = backend()->classify(backendSite("QUEUE_CONNECTION=redis\n"));

    expect($state['state'])->toBe(ServerlessQueueBackend::STATE_SHAREABLE);
    expect($state['reason'])->toBeNull();
});

test('sqs is shareable', function () {
    expect(backend()->classify(backendSite("QUEUE_CONNECTION=sqs\n"))['state'])
        ->toBe(ServerlessQueueBackend::STATE_SHAREABLE);
});

test('a networked database queue is shareable', function () {
    expect(backend()->classify(backendSite("QUEUE_CONNECTION=database\nDB_CONNECTION=pgsql\n"))['state'])
        ->toBe(ServerlessQueueBackend::STATE_SHAREABLE);
});

test('sync is inert', function () {
    expect(backend()->classify(backendSite("QUEUE_CONNECTION=sync\n"))['state'])
        ->toBe(ServerlessQueueBackend::STATE_INERT);
});

test('an unset connection is inert, because the handler defaults it to sync', function () {
    expect(backend()->classify(backendSite("APP_ENV=production\n"))['state'])
        ->toBe(ServerlessQueueBackend::STATE_INERT);
});

test('a sqlite database queue is unshared', function () {
    // The store is a file in a per-container /tmp — concurrent drains each
    // see a different one, so jobs are lost rather than merely delayed.
    expect(backend()->classify(backendSite("QUEUE_CONNECTION=database\nDB_CONNECTION=sqlite\n"))['state'])
        ->toBe(ServerlessQueueBackend::STATE_UNSHARED);
});

test('a database queue with no DB_CONNECTION is treated as unshared', function () {
    // Laravel's own default is sqlite, so an unset DB_CONNECTION is the
    // dangerous case, not a neutral one.
    expect(backend()->classify(backendSite("QUEUE_CONNECTION=database\n"))['state'])
        ->toBe(ServerlessQueueBackend::STATE_UNSHARED);
});

test('an unrecognised driver is unknown, not blocked', function () {
    // A custom driver backed by a real service is valid; refusing it would be
    // worse than the risk of allowing it.
    $site = backendSite("QUEUE_CONNECTION=rabbitmq\n");

    expect(backend()->classify($site)['state'])->toBe(ServerlessQueueBackend::STATE_UNKNOWN);
    expect(backend()->canDrain($site))->toBeTrue();
});

test('canDrain blocks the two provably broken states', function () {
    expect(backend()->canDrain(backendSite("QUEUE_CONNECTION=sync\n")))->toBeFalse();
    expect(backend()->canDrain(backendSite("QUEUE_CONNECTION=database\nDB_CONNECTION=sqlite\n")))->toBeFalse();
    expect(backend()->canDrain(backendSite("QUEUE_CONNECTION=redis\n")))->toBeTrue();
});

test('redis is only offered when a provisioned cache is actually online', function () {
    $noCache = backendSite("QUEUE_CONNECTION=sync\n");
    expect(backend()->classify($noCache)['fixable_with_redis'])->toBeFalse();

    $provisioning = backendSite("QUEUE_CONNECTION=sync\n".REDIS_ENV, ['status' => 'provisioning']);
    expect(backend()->classify($provisioning)['fixable_with_redis'])->toBeFalse();

    $online = backendSite("QUEUE_CONNECTION=sync\n".REDIS_ENV, ['status' => 'online']);
    expect(backend()->classify($online)['fixable_with_redis'])->toBeTrue();
});

test('an online cache with no credentials in env is not offered', function () {
    // Status says online but the env was never written — connecting would fail.
    $site = backendSite("QUEUE_CONNECTION=sync\n", ['status' => 'online']);

    expect(backend()->redisAvailable($site))->toBeFalse();
});

test('wireRedis points the queue at redis', function () {
    $site = backendSite("QUEUE_CONNECTION=sync\n".REDIS_ENV, ['status' => 'online']);

    expect(backend()->wireRedis($site))->toBeTrue();
    expect(backend()->classify($site->fresh())['state'])->toBe(ServerlessQueueBackend::STATE_SHAREABLE);
});

test('adoptRedisIfBroken repairs a sync backend', function () {
    $site = backendSite("QUEUE_CONNECTION=sync\n".REDIS_ENV, ['status' => 'online']);

    expect(backend()->adoptRedisIfBroken($site))->toBeTrue();
    expect(backend()->classify($site->fresh())['connection'])->toBe('redis');
});

test('adoptRedisIfBroken repairs a sqlite backend', function () {
    $site = backendSite("QUEUE_CONNECTION=database\nDB_CONNECTION=sqlite\n".REDIS_ENV, ['status' => 'online']);

    expect(backend()->adoptRedisIfBroken($site))->toBeTrue();
    expect(backend()->classify($site->fresh())['connection'])->toBe('redis');
});

test('adoptRedisIfBroken never overrides a working choice', function () {
    // An operator pointing at their own SQS made a decision. Silently
    // repointing it at our Redis would be a worse failure than the one we
    // are trying to prevent.
    $site = backendSite("QUEUE_CONNECTION=sqs\n".REDIS_ENV, ['status' => 'online']);

    expect(backend()->adoptRedisIfBroken($site))->toBeFalse();
    expect(backend()->classify($site->fresh())['connection'])->toBe('sqs');
});

test('adoptRedisIfBroken leaves an unknown driver alone', function () {
    $site = backendSite("QUEUE_CONNECTION=rabbitmq\n".REDIS_ENV, ['status' => 'online']);

    expect(backend()->adoptRedisIfBroken($site))->toBeFalse();
    expect(backend()->classify($site->fresh())['connection'])->toBe('rabbitmq');
});

test('the pump refuses to open slots on an inert backend', function () {
    // Draining `sync` burns an invocation per wake to process nothing.
    Bus::fake();
    $site = backendSite("QUEUE_CONNECTION=sync\n");

    expect(app(ServerlessQueuePump::class)->wake($site))->toBe(0);
    Bus::assertNothingDispatched();
});

test('the pump refuses to open slots on a sqlite backend', function () {
    Bus::fake();
    $site = backendSite("QUEUE_CONNECTION=database\nDB_CONNECTION=sqlite\n");

    expect(app(ServerlessQueuePump::class)->wake($site))->toBe(0);
    Bus::assertNothingDispatched();
});

test('the pump drains once the backend is repaired', function () {
    Bus::fake();
    $site = backendSite("QUEUE_CONNECTION=sync\n".REDIS_ENV, ['status' => 'online']);

    expect(app(ServerlessQueuePump::class)->wake($site))->toBe(0);

    backend()->adoptRedisIfBroken($site);

    expect(app(ServerlessQueuePump::class)->wake($site->fresh()))->toBe(ServerlessQueuePump::WAKE_RAMP);
    Bus::assertDispatched(ServerlessQueueSlotJob::class);
});
