<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\QueueJobPayloadTest;

use App\Support\Sites\QueueJobPayload;

test('a real Laravel payload yields the job name, attempts and wait', function () {
    // The envelope Laravel writes — identical in the jobs table and a Redis list.
    $json = json_encode([
        'uuid' => 'b1e2',
        'displayName' => 'App\\Jobs\\CompositeGrid',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'attempts' => 0,
        'pushedAt' => 1_700_000_000,
    ]);

    $job = QueueJobPayload::fromJson($json, now: 1_700_000_042);

    expect($job->name)->toBe('CompositeGrid')
        ->and($job->attempts)->toBe(1)
        ->and($job->waitingSeconds)->toBe(42)
        ->and($job->uuid)->toBe('b1e2');
});

test('a clock that disagrees renders as zero, never as negative age', function () {
    $json = json_encode(['displayName' => 'App\\Jobs\\X', 'pushedAt' => 1_700_000_100]);

    expect(QueueJobPayload::fromJson($json, now: 1_700_000_000)->waitingSeconds)->toBe(0);
});

test('a retried job reports the attempt a human would count', function () {
    // Laravel stores attempts as "completed attempts"; a job on its second run
    // is attempts=1 in the payload.
    $json = json_encode(['displayName' => 'App\\Jobs\\X', 'attempts' => 1]);

    expect(QueueJobPayload::fromJson($json)->attempts)->toBe(2);
});

test('unreadable or nameless payloads are skipped rather than rendered blank', function () {
    expect(QueueJobPayload::fromJson('not json'))->toBeNull()
        ->and(QueueJobPayload::fromJson('{"attempts":0}'))->toBeNull()
        ->and(QueueJobPayload::fromJson('{"displayName":"  "}'))->toBeNull();
});

test('wait is omitted when the payload has no push time', function () {
    expect(QueueJobPayload::fromJson('{"displayName":"App\\\\Jobs\\\\X"}', now: 1_700_000_000)->waitingSeconds)->toBeNull();
});
