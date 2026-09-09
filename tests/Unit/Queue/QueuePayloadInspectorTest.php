<?php

declare(strict_types=1);

namespace Tests\Unit\Queue\QueuePayloadInspectorTest;

use App\Modules\Queue\Services\QueuePayloadInspector;

function inspector(): QueuePayloadInspector
{
    return new QueuePayloadInspector;
}

beforeEach(function () {
    config([
        'queue_service.reservation.default_visibility_seconds' => 60,
        'queue_service.reservation.lease_grace_seconds' => 15,
        'queue_service.reservation.max_visibility_seconds' => 43200,
    ]);
});

test('it reads the fields Laravel serializes in plaintext', function () {
    // Only data.command is ever encrypted, so everything below is free.
    $payload = (string) json_encode([
        'uuid' => 'e7a1-uuid',
        'displayName' => 'App\\Jobs\\SendInvoice',
        'maxTries' => 5,
        'timeout' => 120,
        'data' => ['batchId' => 'batch-9', 'command' => 'O:8:"Whatever":0:{}'],
    ]);

    expect(inspector()->inspect($payload))->toBe([
        'job_uuid' => 'e7a1-uuid',
        'job_timeout' => 120,
        'job_max_tries' => 5,
        'batch_id' => 'batch-9',
        'display_name' => 'App\\Jobs\\SendInvoice',
        'group_key' => null,
    ]);
});

test('the FIFO group key is read from either spelling', function () {
    // dply's own producers set groupKey; SQS clients send messageGroupId. Both
    // mean "these run in order", so both have to land in the same column.
    expect(inspector()->inspect((string) json_encode(['data' => ['groupKey' => 'tenant-7']]))['group_key'])->toBe('tenant-7');
    expect(inspector()->inspect((string) json_encode(['messageGroupId' => 'tenant-8']))['group_key'])->toBe('tenant-8');
});

test('a non-JSON payload yields nulls rather than throwing', function () {
    // A raw string producer, or a non-Laravel one, must not break the queue.
    expect(inspector()->inspect('not json at all'))->toBe([
        'job_uuid' => null,
        'job_timeout' => null,
        'job_max_tries' => null,
        'batch_id' => null,
        'display_name' => null,
        'group_key' => null,
    ]);
});

test('JSON that is not an envelope yields nulls', function () {
    expect(inspector()->inspect('{"something":"else"}')['job_timeout'])->toBeNull();
});

test('zero and negative timeouts are treated as absent', function () {
    // Laravel uses 0 to mean "no timeout"; clamping against it would produce
    // a lease shorter than the default.
    expect(inspector()->inspect('{"timeout":0}')['job_timeout'])->toBeNull();
    expect(inspector()->inspect('{"timeout":-5}')['job_timeout'])->toBeNull();
});

test('oversized strings are bounded to the column widths', function () {
    $long = str_repeat('a', 500);
    $result = inspector()->inspect((string) json_encode(['displayName' => $long, 'uuid' => $long]));

    expect(strlen((string) $result['display_name']))->toBe(255);
    expect(strlen((string) $result['job_uuid']))->toBe(64);
});

test('the lease defaults when nothing is known', function () {
    expect(inspector()->leaseSeconds(null, null))->toBe(60);
});

test('the lease honours what the consumer asked for', function () {
    expect(inspector()->leaseSeconds(null, 30))->toBe(30);
});

test('a job that needs longer than requested gets the longer lease', function () {
    // THE differentiator. Laravel's rule is retry_after > timeout, and
    // violating it means the job is re-reserved mid-run and processed twice.
    // Because dply owns the lease, a worker that under-requests cannot cause
    // its own job to be re-delivered — the misconfiguration is unrepresentable.
    expect(inspector()->leaseSeconds(300, 30))->toBe(315); // 300 + 15s grace
});

test('the grace allowance covers the gap between finishing and acking', function () {
    // Without it a job running for exactly its timeout would race its own lease.
    expect(inspector()->leaseSeconds(60, 60))->toBe(75);
});

test('a generous request is honoured over a short job timeout', function () {
    expect(inspector()->leaseSeconds(10, 600))->toBe(600);
});

test('the lease is bounded by the platform maximum', function () {
    // So a client cannot park a job for a day by asking nicely.
    expect(inspector()->leaseSeconds(null, 999_999))->toBe(43200);
    expect(inspector()->leaseSeconds(999_999, null))->toBe(43200);
});

test('the lease is never zero or negative', function () {
    expect(inspector()->leaseSeconds(null, 0))->toBe(60);
    expect(inspector()->leaseSeconds(null, -10))->toBe(60);
});
