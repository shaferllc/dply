<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\FleetWakeOnPushTest;

use App\Models\Organization;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Contracts\WorkerRuntime;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Services\Runtimes\FakeWorkerRuntime;
use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'queue_service.fleets.runtime' => 'fake',
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
        'queue_service.entitlements.defaults' => [
            'available' => true,
            'max_namespaces' => 5,
            'max_queue_depth' => 0,
            'max_payload_bytes' => 262144,
            'requests_per_minute' => 600,
        ],
        'queue_service.entitlements.plans' => [],
    ]);

    Cache::flush();

    $this->runtime = app(FakeWorkerRuntime::class);
    $this->app->instance(WorkerRuntime::class, $this->runtime);

    $org = Organization::factory()->create();
    $created = app(CreateQueueNamespace::class)->handle($org, 'orders');

    $this->ctx = $created;

    $this->fleet = ManagedQueueFleet::query()->create([
        'namespace_id' => $created['namespace']->id,
        'organization_id' => $org->id,
        'queue' => 'default',
        'class' => ManagedQueueFleet::CLASS_FLEX,
        'status' => ManagedQueueFleet::STATUS_ACTIVE,
        'memory_mib' => 256,
        'min_workers' => 0,
        'max_workers' => 5,
        'meta' => ['image' => 'registry.dply.test/app:latest'],
    ]);
});

/** Signed by the AWS SDK's own SignatureV4, exactly as a customer's app would. */
function send(array $ctx, string $body = '{"job":"x"}'): TestResponse
{
    $url = url('/api/queue/v1');
    $payload = (string) json_encode(['MessageBody' => $body]);

    $psr = new PsrRequest('POST', $url, [
        'Content-Type' => 'application/x-amz-json-1.0',
        'X-Amz-Target' => 'AmazonSQS.SendMessage',
        'Host' => parse_url($url, PHP_URL_HOST),
    ], $payload);

    $signed = (new SignatureV4('sqs', 'us-east-1'))->signRequest(
        $psr,
        new Credentials($ctx['credential']->accessKeyId(), $ctx['plaintext']),
    );

    $server = [];
    foreach ($signed->getHeaders() as $name => $values) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
    }
    $server['CONTENT_TYPE'] = 'application/x-amz-json-1.0';

    return test()->call('POST', $url, [], [], [], $server, $payload);
}

test('dispatching to a sleeping fleet starts a worker on the push itself', function () {
    expect($this->runtime->runningCount())->toBe(0);

    send($this->ctx)->assertOk();

    expect($this->runtime->runningCount())->toBe(1)
        ->and(ManagedQueueWorker::query()->live()->count())->toBe(1);
});

/** The push must succeed on its own terms whatever the fleet does. */
test('a push still succeeds when the fleet cannot be woken', function () {
    $this->runtime->failNextStart();

    send($this->ctx)->assertOk()->assertJsonStructure(['MessageId']);

    expect($this->runtime->runningCount())->toBe(0);
});
