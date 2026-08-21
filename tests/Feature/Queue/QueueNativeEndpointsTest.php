<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueNativeEndpointsTest;

use App\Models\Organization;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Actions\RevokeQueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The dply-native half of the queue API — failed jobs.
 *
 * Locks used to live here too. They were retired with the queue's own lock
 * store (docs/adr/dply-cache.md, decision 8): the endpoints had no client,
 * because stock Laravel's `Cache::lock()` reaches the configured cache store
 * and nothing else. Locks now come from dply Cache through `DynamoDbLock`.
 *
 * Bearer-authenticated rather than SigV4: these are not SQS operations, so
 * there is no compatibility contract to honour, and requiring a signature
 * would mean shipping a signer into every customer app.
 *
 * @return array{secret: string, namespace: QueueNamespace}
 */
function nativeCtx(): array
{
    $org = Organization::factory()->create();
    config(['queue_service.entitlements.defaults.max_namespaces' => 5]);
    config(['queue_service.entitlements.plans' => []]);

    $created = app(CreateQueueNamespace::class)->handle($org, 'orders');

    return ['secret' => $created['plaintext'], 'namespace' => $created['namespace']];
}

/** @param array<string, mixed> $payload */
function nativeCall(array $ctx, string $method, string $path, array $payload = []): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$ctx['secret']])
        ->json($method, '/api/queue/v1'.$path, $payload);
}

test('a bearer token authenticates the native endpoints', function () {
    $ctx = nativeCtx();

    nativeCall($ctx, 'GET', '/failed-jobs')
        ->assertOk()
        ->assertJsonStructure(['failed_jobs']);
});

test('an unknown bearer token is rejected', function () {
    $ctx = nativeCtx();
    $ctx['secret'] = 'not-a-real-secret';

    nativeCall($ctx, 'GET', '/failed-jobs')->assertStatus(403);
});

test('a revoked credential loses access to the native endpoints too', function () {
    $ctx = nativeCtx();
    nativeCall($ctx, 'GET', '/failed-jobs')->assertOk();

    $credential = $ctx['namespace']->credentials()->first();
    app(RevokeQueueCredential::class)->handle($credential);

    nativeCall($ctx, 'GET', '/failed-jobs')->assertStatus(403);
});


test('a failed job is recorded and listed', function () {
    $ctx = nativeCtx();
    $payload = (string) json_encode(['uuid' => 'job-1', 'displayName' => 'App\\Jobs\\Broken']);

    nativeCall($ctx, 'POST', '/failed-jobs', [
        'uuid' => 'job-1',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => 'RuntimeException: boom',
        'attempts' => 3,
    ])->assertStatus(201);

    $listed = nativeCall($ctx, 'GET', '/failed-jobs')->assertOk()->json('failed_jobs');

    expect($listed)->toHaveCount(1);
    expect($listed[0]['id'])->toBe('job-1');
    expect($listed[0]['exception'])->toContain('boom');
    expect($listed[0]['connection'])->toBe('dply');
});

test('re-failing the same job updates one row rather than accumulating', function () {
    // A retried job that fails again must not produce a second entry, or the
    // panel over-reports how many jobs are actually broken.
    $ctx = nativeCtx();
    $payload = (string) json_encode(['uuid' => 'job-1']);

    nativeCall($ctx, 'POST', '/failed-jobs', ['uuid' => 'job-1', 'payload' => $payload, 'exception' => 'first']);
    nativeCall($ctx, 'POST', '/failed-jobs', ['uuid' => 'job-1', 'payload' => $payload, 'exception' => 'second']);

    $listed = nativeCall($ctx, 'GET', '/failed-jobs')->json('failed_jobs');

    expect($listed)->toHaveCount(1);
    expect($listed[0]['exception'])->toBe('second');
});

test('a failed job with no uuid is still recorded', function () {
    $ctx = nativeCtx();

    nativeCall($ctx, 'POST', '/failed-jobs', ['payload' => 'raw', 'exception' => 'boom'])->assertStatus(201);

    expect(nativeCall($ctx, 'GET', '/failed-jobs')->json('failed_jobs'))->toHaveCount(1);
});

test('a payload is required', function () {
    $ctx = nativeCtx();

    nativeCall($ctx, 'POST', '/failed-jobs', ['uuid' => 'x'])->assertStatus(400);
});

test('a failed job can be found and forgotten by uuid', function () {
    // queue:retry and queue:forget address jobs by the id `all()` returned,
    // which is the uuid when there is one.
    $ctx = nativeCtx();
    nativeCall($ctx, 'POST', '/failed-jobs', [
        'uuid' => 'job-9',
        'payload' => (string) json_encode(['uuid' => 'job-9']),
        'exception' => 'boom',
    ]);

    nativeCall($ctx, 'GET', '/failed-jobs/job-9')->assertOk()->assertJsonPath('failed_job.uuid', 'job-9');

    nativeCall($ctx, 'DELETE', '/failed-jobs/job-9')->assertOk()->assertJson(['forgotten' => true]);

    expect(nativeCall($ctx, 'GET', '/failed-jobs')->json('failed_jobs'))->toBe([]);
});

test('finding an unknown failed job is a 404', function () {
    $ctx = nativeCtx();

    nativeCall($ctx, 'GET', '/failed-jobs/nope')->assertStatus(404);
});

test('flush empties this namespace only', function () {
    $mine = nativeCtx();
    $theirs = nativeCtx();

    nativeCall($mine, 'POST', '/failed-jobs', ['uuid' => 'a', 'payload' => 'p', 'exception' => 'e']);
    nativeCall($theirs, 'POST', '/failed-jobs', ['uuid' => 'b', 'payload' => 'p', 'exception' => 'e']);

    nativeCall($mine, 'POST', '/failed-jobs/flush')->assertOk()->assertJson(['flushed' => 1]);

    expect(nativeCall($mine, 'GET', '/failed-jobs')->json('failed_jobs'))->toBe([]);
    expect(nativeCall($theirs, 'GET', '/failed-jobs')->json('failed_jobs'))->toHaveCount(1);
});

test('one namespace cannot read or forget another namespace failed jobs', function () {
    $mine = nativeCtx();
    $theirs = nativeCtx();

    nativeCall($theirs, 'POST', '/failed-jobs', [
        'uuid' => 'secret-job',
        'payload' => (string) json_encode(['uuid' => 'secret-job']),
        'exception' => 'theirs',
    ]);

    nativeCall($mine, 'GET', '/failed-jobs')->assertOk()->assertJsonPath('failed_jobs', []);
    nativeCall($mine, 'GET', '/failed-jobs/secret-job')->assertStatus(404);
    nativeCall($mine, 'DELETE', '/failed-jobs/secret-job')->assertJson(['forgotten' => false]);

    expect(nativeCall($theirs, 'GET', '/failed-jobs')->json('failed_jobs'))->toHaveCount(1);
});

test('the native routes are matched before the SQS catch-all', function () {
    // `/failed-jobs` would otherwise be read as a queue named "failed-jobs".
    $ctx = nativeCtx();

    nativeCall($ctx, 'GET', '/failed-jobs')
        ->assertOk()
        ->assertJsonStructure(['failed_jobs']);
});
