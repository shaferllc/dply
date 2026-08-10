<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessQueueBackendTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\ServerlessQueueProvisioner;
use App\Modules\Serverless\Jobs\ServerlessQueueSlotJob;
use App\Modules\Serverless\Services\ServerlessQueueBackend;
use App\Modules\Serverless\Services\ServerlessQueuePump;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

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

test('the dply connection is shareable', function () {
    expect(backend()->classify(backendSite("QUEUE_CONNECTION=dply\n"))['state'])
        ->toBe(ServerlessQueueBackend::STATE_SHAREABLE);
    expect(backend()->canDrain(backendSite("QUEUE_CONNECTION=dply\n")))->toBeTrue();
});

test('repairIfBroken prefers dply Queue over Redis', function () {
    // The product's entry point. Before dply Queue, a broken backend could
    // only be fixed by provisioning a paid Redis; now a namespace is a row and
    // is usable immediately, so it wins even when Redis is already online.
    config([
        'queue_service.enabled' => true,
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
    ]);
    $site = backendSite("QUEUE_CONNECTION=sync\n".REDIS_ENV, ['status' => 'online']);
    Feature::define('surface.queue', fn (): bool => true);

    expect(backend()->repairIfBroken($site))->toBe('dply');

    $fresh = $site->fresh();
    expect(backend()->classify($fresh)['connection'])->toBe('dply');
    expect((string) $fresh->env_file_content)->toContain('DPLY_QUEUE_URL=');
    expect((string) $fresh->env_file_content)->toContain('DPLY_QUEUE_KEY=');
});

test('repairIfBroken falls back to Redis when dply Queue is unavailable', function () {
    config(['queue_service.enabled' => false]);
    $site = backendSite("QUEUE_CONNECTION=sync\n".REDIS_ENV, ['status' => 'online']);

    expect(backend()->repairIfBroken($site))->toBe('redis');
    expect(backend()->classify($site->fresh())['connection'])->toBe('redis');
});

test('repairIfBroken never touches a working backend', function () {
    config([
        'queue_service.enabled' => true,
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
    ]);
    Feature::define('surface.queue', fn (): bool => true);
    $site = backendSite("QUEUE_CONNECTION=sqs\n");

    expect(backend()->repairIfBroken($site))->toBeNull();
    expect(backend()->classify($site->fresh())['connection'])->toBe('sqs');
});

test('repairIfBroken reports null when nothing is available', function () {
    config(['queue_service.enabled' => false]);
    $site = backendSite("QUEUE_CONNECTION=sync\n");

    expect(backend()->repairIfBroken($site))->toBeNull();
});

test('wiring a site twice reuses its namespace and credential', function () {
    // Re-minting on every deploy would churn credentials for no reason and
    // burn the two-live-credential rotation budget.
    config([
        'queue_service.enabled' => true,
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
    ]);
    Feature::define('surface.queue', fn (): bool => true);
    $site = backendSite("QUEUE_CONNECTION=sync\n");

    $provisioner = app(ServerlessQueueProvisioner::class);
    expect($provisioner->wire($site))->toBeTrue();
    $first = (string) $site->fresh()->env_file_content;

    expect($provisioner->wire($site->fresh()))->toBeTrue();
    $second = (string) $site->fresh()->env_file_content;

    expect(QueueNamespace::query()->where('site_id', $site->id)->count())->toBe(1);
    expect($second)->toBe($first);
});

/** The two-key gate the provisioner enforces: platform configured AND org flagged. */
function enableDplyQueue(): void
{
    config([
        'queue_service.enabled' => true,
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
    ]);
    Feature::define('surface.queue', fn (): bool => true);
}

test('a broken backend is offered dply Queue when the org has it', function () {
    // What the panel branches on. Unlike Redis this does not depend on any
    // infrastructure being online — there is nothing to provision first.
    $site = backendSite("QUEUE_CONNECTION=sync\n");
    expect(backend()->classify($site)['fixable_with_dply'])->toBeFalse();

    enableDplyQueue();

    expect(backend()->classify($site)['fixable_with_dply'])->toBeTrue();
});

test('a sqlite backend is offered dply Queue', function () {
    enableDplyQueue();

    expect(backend()->classify(backendSite("QUEUE_CONNECTION=database\nDB_CONNECTION=sqlite\n"))['fixable_with_dply'])
        ->toBeTrue();
});

test('a working backend is offered no repair at all', function () {
    // Both flags are false on a shareable backend: offering to repoint a queue
    // that works would be the more destructive answer.
    enableDplyQueue();
    $state = backend()->classify(backendSite("QUEUE_CONNECTION=sqs\n".REDIS_ENV, ['status' => 'online']));

    expect($state['fixable_with_dply'])->toBeFalse();
    expect($state['fixable_with_redis'])->toBeFalse();
});

test('wireDplyQueue creates a namespace and points the connection at it', function () {
    enableDplyQueue();
    $site = backendSite("QUEUE_CONNECTION=sync\n");

    expect(backend()->wireDplyQueue($site))->toBeTrue();

    $fresh = $site->fresh();
    expect(backend()->classify($fresh)['connection'])->toBe('dply');
    expect(backend()->canDrain($fresh))->toBeTrue();
    expect(QueueNamespace::query()->where('site_id', $site->id)->count())->toBe(1);
});

test('wireDplyQueue refuses when the org does not have dply Queue', function () {
    config(['queue_service.enabled' => false]);
    $site = backendSite("QUEUE_CONNECTION=sync\n");

    expect(backend()->wireDplyQueue($site))->toBeFalse();
    expect(QueueNamespace::query()->where('site_id', $site->id)->count())->toBe(0);
});

test('managedQueue is null until a namespace exists', function () {
    enableDplyQueue();

    expect(backend()->managedQueue(backendSite("QUEUE_CONNECTION=sync\n")))->toBeNull();
});

test('managedQueue reports the endpoint and live depth', function () {
    enableDplyQueue();
    $site = backendSite("QUEUE_CONNECTION=sync\n");
    backend()->wireDplyQueue($site);

    $described = backend()->managedQueue($site->fresh());
    $namespace = $described['namespace'];

    // The ULID is the data-plane id, so it is the endpoint's last segment —
    // nothing translates between a public name and an internal one.
    expect($described['endpoint'])->toBe('https://queue.dply.test/api/queue/v1/'.$namespace->id);
    expect($described['depth']->total())->toBe(0);

    app(QueueStore::class)->push($namespace, 'default', envelopeFor('App\\Jobs\\SendInvoice'));

    expect(backend()->managedQueue($site->fresh())['depth']->pending)->toBe(1);
});

/** A realistic Laravel job envelope — the store reads `timeout` off it. */
function envelopeFor(string $class): string
{
    return (string) json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => $class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'timeout' => 60,
        'data' => ['commandName' => $class, 'command' => 'O:0:"":0:{}'],
    ]);
}

test('a namespace outlives a connection pointed elsewhere by hand', function () {
    // The panel shows the queue whenever it exists: someone editing
    // QUEUE_CONNECTION in the Environment panel does not delete the backlog,
    // and hiding it is how an operator loses one.
    enableDplyQueue();
    $site = backendSite("QUEUE_CONNECTION=sync\n");
    backend()->wireDplyQueue($site);

    app(ServerlessEnvironmentPreparer::class)
        ->mergeKeys($site->fresh(), ['QUEUE_CONNECTION' => 'sqs']);

    $fresh = $site->fresh();
    expect(backend()->classify($fresh)['connection'])->toBe('sqs');
    expect(backend()->managedQueue($fresh))->not->toBeNull();
});

test('dply Queue is not offered without a public url', function () {
    // Same reachability rule as log ingest: a function on DigitalOcean cannot
    // reach a local *.test address, so the feature is simply not offered.
    config(['queue_service.enabled' => true, 'queue_service.public_url' => '', 'dply.public_app_url' => '']);
    Feature::define('surface.queue', fn (): bool => true);
    $site = backendSite("QUEUE_CONNECTION=sync\n");

    expect(app(ServerlessQueueProvisioner::class)->available($site))->toBeFalse();
});
