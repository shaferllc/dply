<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueUsageMeterTest;

use App\Models\Organization;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Models\QueueUsageDaily;
use App\Modules\Queue\Services\QueueUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * These exercise real Redis. The meter's whole reason for existing is that a
 * completed job's row is deleted, so a test that faked the counter would prove
 * nothing about the only path that carries the billable number.
 */
beforeEach(function () {
    clearQueueCounters();
});

afterEach(function () {
    clearQueueCounters();
});

function clearQueueCounters(): void
{
    $members = Redis::smembers('dplyq:usage:index');

    foreach (is_array($members) ? $members : [] as $member) {
        [$org, $day] = array_pad(explode(':', (string) $member), 2, '');
        Redis::del('dplyq:usage:'.$org.':'.$day);
    }

    Redis::del('dplyq:usage:index');
}

function meterNamespace(): QueueNamespace
{
    $org = Organization::factory()->create();

    return QueueNamespace::query()->create([
        'organization_id' => $org->id,
        'name' => 'orders',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
}

function meterEnvelope(): string
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

test('pushing a job counts it', function () {
    $namespace = meterNamespace();
    $meter = app(QueueUsageMeter::class);

    app(QueueStore::class)->push($namespace, 'default', meterEnvelope());
    $meter->flush();

    $row = QueueUsageDaily::query()
        ->where('organization_id', $namespace->organization_id)
        ->firstOrFail();

    expect($row->jobs_pushed)->toBe(1);
    expect($row->source)->toBe(QueueUsageDaily::SOURCE_COUNTER);
});

test('a bulk push counts every job in it', function () {
    $namespace = meterNamespace();

    app(QueueStore::class)->pushBulk($namespace, 'default', [
        meterEnvelope(), meterEnvelope(), meterEnvelope(),
    ]);
    app(QueueUsageMeter::class)->flush();

    expect(QueueUsageDaily::query()->where('organization_id', $namespace->organization_id)->value('jobs_pushed'))
        ->toBe(3);
});

test('a completed job still counts', function () {
    // The reason this is a counter and not a query over the store: an acked
    // job's row is gone, so metering after the fact would bill only for the
    // backlog — precisely the usage that costs us least.
    $namespace = meterNamespace();
    $store = app(QueueStore::class);

    $store->push($namespace, 'default', meterEnvelope());
    $claimed = $store->claim($namespace, 'default');
    $store->ack($namespace, $claimed[0]->id, $claimed[0]->reservationId);

    expect($store->depth($namespace)->total())->toBe(0);

    app(QueueUsageMeter::class)->flush();

    expect(QueueUsageDaily::query()->where('organization_id', $namespace->organization_id)->value('jobs_pushed'))
        ->toBe(1);
});

test('flushing twice does not double-count', function () {
    // The counter holds a running total and the row is written absolute, so a
    // re-run — or a flush racing a push — can only ever be a no-op.
    $namespace = meterNamespace();
    $meter = app(QueueUsageMeter::class);

    app(QueueStore::class)->pushBulk($namespace, 'default', [meterEnvelope(), meterEnvelope()]);

    $meter->flush();
    $meter->flush();

    expect(QueueUsageDaily::query()->where('organization_id', $namespace->organization_id)->count())->toBe(1);
    expect(QueueUsageDaily::query()->where('organization_id', $namespace->organization_id)->value('jobs_pushed'))
        ->toBe(2);
});

test('pushes landing after a flush are picked up by the next one', function () {
    $namespace = meterNamespace();
    $meter = app(QueueUsageMeter::class);
    $store = app(QueueStore::class);

    $store->push($namespace, 'default', meterEnvelope());
    $meter->flush();

    $store->push($namespace, 'default', meterEnvelope());
    $meter->flush();

    expect(QueueUsageDaily::query()->where('organization_id', $namespace->organization_id)->value('jobs_pushed'))
        ->toBe(2);
});

test('a dry run reports without writing', function () {
    $namespace = meterNamespace();
    app(QueueStore::class)->push($namespace, 'default', meterEnvelope());

    $result = app(QueueUsageMeter::class)->flush(dryRun: true);

    expect($result['jobs'])->toBe(1);
    expect($result['orgs'])->toBe(1);
    expect(QueueUsageDaily::query()->count())->toBe(0);
});

test('a counter for a deleted org is skipped rather than failing the flush', function () {
    // A usage row for a missing org would violate the FK and take the whole
    // scheduled flush down with it.
    $namespace = meterNamespace();
    app(QueueStore::class)->push($namespace, 'default', meterEnvelope());

    Organization::query()->whereKey($namespace->organization_id)->delete();

    $result = app(QueueUsageMeter::class)->flush();

    expect($result['skipped'])->toBe(1);
    expect(QueueUsageDaily::query()->count())->toBe(0);
});

test('month-to-date reads the rolled-up rows', function () {
    $namespace = meterNamespace();
    $organization = $namespace->organization;

    QueueUsageDaily::query()->create([
        'organization_id' => $organization->id,
        'day' => now()->utc()->startOfMonth()->toDateString(),
        'jobs_pushed' => 400,
        'source' => QueueUsageDaily::SOURCE_COUNTER,
    ]);
    QueueUsageDaily::query()->create([
        'organization_id' => $organization->id,
        'day' => now()->utc()->toDateString(),
        'jobs_pushed' => 250,
        'source' => QueueUsageDaily::SOURCE_MANUAL,
    ]);

    expect(app(QueueUsageMeter::class)->monthToDateJobs($organization))->toBe(650);
});

test('a counter outage does not fail the push', function () {
    // Metering is dply's problem. Rejecting the customer's job because we
    // could not count it would be the wrong trade every time, so the meter
    // swallows its own failures and the push goes through regardless.
    $namespace = meterNamespace();

    // Port 1 is what a counter outage actually looks like from the store side.
    config(['database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
    app()->forgetInstance('redis');
    app()->forgetInstance('redis.connection');

    $id = app(QueueStore::class)->push($namespace, 'default', meterEnvelope());

    expect($id)->not->toBeEmpty();
    expect(app(QueueStore::class)->depth($namespace)->pending)->toBe(1);
});
