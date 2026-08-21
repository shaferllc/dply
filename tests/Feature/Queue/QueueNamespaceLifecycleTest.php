<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueNamespaceLifecycleTest;

use App\Models\Organization;
use App\Models\ServiceCredential;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Actions\DeleteQueueNamespace;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Models\QueueUsageDaily;
use App\Modules\Queue\Services\QueueUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function lifecycleNamespace(): QueueNamespace
{
    config([
        'queue_service.entitlements.defaults' => [
            'available' => true,
            'max_namespaces' => 5,
            'max_queue_depth' => 0,
        ],
        'queue_service.entitlements.plans' => [],
    ]);

    return app(CreateQueueNamespace::class)
        ->handle(Organization::factory()->create(), 'orders')['namespace'];
}

function lifecycleEnvelope(): string
{
    return (string) json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => 'App\\Jobs\\SendInvoice',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'timeout' => 60,
        'data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => 'O:0:"":0:{}'],
    ]);
}

test('deleting a namespace purges its jobs', function () {
    // The job tables live on a different connection, so no foreign key can
    // cascade this. Without the purge the rows would survive with nothing left
    // able to reach, drain, or bill for them.
    $namespace = lifecycleNamespace();
    $store = app(QueueStore::class);

    $store->pushBulk($namespace, 'default', [lifecycleEnvelope(), lifecycleEnvelope()]);
    expect($store->depth($namespace)->total())->toBe(2);

    $result = app(DeleteQueueNamespace::class)->handle($namespace);

    expect($result['jobs'])->toBe(2);
    expect(QueueNamespace::query()->whereKey($namespace->id)->exists())->toBeFalse();
    expect(DB::connection('dply_queue')->table('dply_queue_jobs')->where('namespace_id', $namespace->id)->count())
        ->toBe(0);
});

test('deleting a namespace revokes its credentials first', function () {
    // Revoked before the purge, so a client mid-flight cannot enqueue a job
    // into a namespace that is being torn down.
    $namespace = lifecycleNamespace();

    $result = app(DeleteQueueNamespace::class)->handle($namespace);

    expect($result['credentials'])->toBe(1);
    expect(ServiceCredential::query()->forResource(ServiceCredential::SERVICE_QUEUE, $namespace->id)->count())->toBe(0);
});

test('deleting a namespace leaves usage history alone', function () {
    // The month a namespace was billed for outlives the namespace.
    $namespace = lifecycleNamespace();
    $store = app(QueueStore::class);

    $store->push($namespace, 'default', lifecycleEnvelope());
    app(QueueUsageMeter::class)->flush();

    $before = QueueUsageDaily::query()
        ->where('organization_id', $namespace->organization_id)
        ->value('jobs_pushed');

    app(DeleteQueueNamespace::class)->handle($namespace);

    expect(QueueUsageDaily::query()
        ->where('organization_id', $namespace->organization_id)
        ->value('jobs_pushed'))->toBe($before);
});

test('purge also clears failed jobs', function () {
    $namespace = lifecycleNamespace();
    $store = app(QueueStore::class);

    $store->push($namespace, 'default', lifecycleEnvelope());
    $claimed = $store->claim($namespace, 'default');
    $store->fail($namespace, $claimed[0]->id, $claimed[0]->reservationId, 'boom');

    expect(DB::connection('dply_queue')->table('dply_queue_failed_jobs')->where('namespace_id', $namespace->id)->count())
        ->toBe(1);

    $result = $store->purge($namespace);

    expect($result['failed'])->toBe(1);
});

test('a paused namespace stops accepting pushes', function () {
    $namespace = lifecycleNamespace();

    expect($namespace->acceptsPushes())->toBeTrue();

    $namespace->forceFill(['status' => QueueNamespace::STATUS_PAUSED])->save();

    expect($namespace->fresh()->acceptsPushes())->toBeFalse();
});
