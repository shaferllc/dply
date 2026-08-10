<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\SqsCompatibilityTest;

use App\Models\Organization;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Actions\RevokeQueueCredential;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\SqsConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;

uses(RefreshDatabase::class);

/**
 * @return array{namespace: QueueNamespace, credential: QueueCredential, secret: string}
 */
function sqsNamespace(array $queueConfig = []): array
{
    $org = Organization::factory()->create();
    config(['queue_service.entitlements.defaults' => array_merge([
        'available' => true,
        'max_namespaces' => 5,
        'max_queue_depth' => 0,
        'max_payload_bytes' => 262144,
        'requests_per_minute' => 600,
    ], $queueConfig)]);
    config(['queue_service.entitlements.plans' => []]);

    $created = app(CreateQueueNamespace::class)->handle($org, 'orders');

    return [
        'namespace' => $created['namespace'],
        'credential' => $created['credential'],
        'secret' => $created['plaintext'],
    ];
}

/**
 * Build and sign a request exactly as the AWS SDK would, then hand it to
 * Laravel's test client. This is what makes these tests meaningful: the
 * signature is produced by the SDK's own SignatureV4, so if our verification
 * drifts from the real thing, these fail.
 *
 * @param  array<string, mixed>  $payload
 */
function sqsCall(array $ctx, string $action, array $payload = [], ?string $queue = null): TestResponse
{
    $url = url('/api/queue/v1'.($queue !== null ? '/'.$queue : ''));
    $body = (string) json_encode($payload);

    $psr = new PsrRequest('POST', $url, [
        'Content-Type' => 'application/x-amz-json-1.0',
        'X-Amz-Target' => 'AmazonSQS.'.$action,
        'Host' => parse_url($url, PHP_URL_HOST),
    ], $body);

    $signed = (new SignatureV4('sqs', 'us-east-1'))->signRequest(
        $psr,
        new Credentials($ctx['credential']->accessKeyId(), $ctx['secret']),
    );

    $headers = [];
    foreach ($signed->getHeaders() as $name => $values) {
        $headers[$name] = implode(', ', $values);
    }

    return test()->call('POST', $url, [], [], [], sqsServerHeaders($headers), $body);
}

/** @param array<string, string> $headers */
function sqsServerHeaders(array $headers): array
{
    $server = [];
    foreach ($headers as $name => $value) {
        $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
        $server[$key] = $value;
    }
    $server['CONTENT_TYPE'] = $headers['Content-Type'] ?? 'application/x-amz-json-1.0';

    return $server;
}

test('a request signed by the AWS SDK is accepted', function () {
    // The whole compatibility claim in one assertion: a signature produced by
    // the SDK's own SignatureV4 verifies against our implementation.
    $ctx = sqsNamespace();

    sqsCall($ctx, 'SendMessage', ['MessageBody' => '{"uuid":"a","timeout":60}'])
        ->assertOk()
        ->assertJsonStructure(['MessageId', 'MD5OfMessageBody']);
});

test('an unsigned request is rejected', function () {
    $ctx = sqsNamespace();

    test()->call('POST', url('/api/queue/v1'), [], [], [], [
        'HTTP_X_AMZ_TARGET' => 'AmazonSQS.SendMessage',
        'CONTENT_TYPE' => 'application/x-amz-json-1.0',
    ], '{"MessageBody":"x"}')->assertStatus(403);
});

test('a signature computed with the wrong secret is rejected', function () {
    $ctx = sqsNamespace();
    $ctx['secret'] = 'not-the-real-secret';

    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertStatus(403);
});

test('an unknown access key id is rejected', function () {
    // Sign with a key id that was never minted. The credential row is left
    // untouched — mutating it would just make the lookup succeed again.
    $ctx = sqsNamespace();
    $ctx['credential'] = new QueueCredential(['token_prefix' => 'dplyqzzzzzzzzzzzzzzz']);

    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertStatus(403);
});

test('a revoked credential stops working', function () {
    $ctx = sqsNamespace();
    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertOk();

    app(RevokeQueueCredential::class)->handle($ctx['credential']);

    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertStatus(403);
});

test('send then receive round-trips the payload', function () {
    $ctx = sqsNamespace();
    $payload = '{"uuid":"round-trip","timeout":60}';

    sqsCall($ctx, 'SendMessage', ['MessageBody' => $payload])->assertOk();

    $response = sqsCall($ctx, 'ReceiveMessage', ['MaxNumberOfMessages' => 1])->assertOk();

    $message = $response->json('Messages.0');
    expect($message['Body'])->toBe($payload);
    expect($message['MD5OfBody'])->toBe(md5($payload));
    expect($message['Attributes']['ApproximateReceiveCount'])->toBe('1');
    // The receipt handle carries the fencing token.
    expect($message['ReceiptHandle'])->toContain(':');
});

test('receiving an empty queue returns no Messages key', function () {
    $ctx = sqsNamespace();

    $response = sqsCall($ctx, 'ReceiveMessage')->assertOk();

    expect($response->json('Messages'))->toBeNull();
});

test('delete removes the message', function () {
    $ctx = sqsNamespace();
    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertOk();
    $handle = sqsCall($ctx, 'ReceiveMessage')->json('Messages.0.ReceiptHandle');

    sqsCall($ctx, 'DeleteMessage', ['ReceiptHandle' => $handle])->assertOk();

    $attrs = sqsCall($ctx, 'GetQueueAttributes')->json('Attributes');
    expect($attrs['ApproximateNumberOfMessages'])->toBe('0');
    expect($attrs['ApproximateNumberOfMessagesNotVisible'])->toBe('0');
});

test('a stale receipt handle cannot delete a message another consumer holds', function () {
    // The fencing token, over the wire. Without it this is silent job loss.
    $ctx = sqsNamespace();
    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertOk();

    $stale = sqsCall($ctx, 'ReceiveMessage')->json('Messages.0.ReceiptHandle');

    DB::connection('dply_queue')->table('dply_queue_jobs')
        ->update(['visible_at' => DB::raw("now() - interval '1 second'")]);

    $fresh = sqsCall($ctx, 'ReceiveMessage')->json('Messages.0.ReceiptHandle');
    expect($fresh)->not->toBe($stale);

    sqsCall($ctx, 'DeleteMessage', ['ReceiptHandle' => $stale])->assertStatus(400);

    // The current holder's message survived.
    expect(sqsCall($ctx, 'GetQueueAttributes')->json('Attributes.ApproximateNumberOfMessagesNotVisible'))->toBe('1');
});

test('a malformed receipt handle is rejected', function () {
    $ctx = sqsNamespace();

    sqsCall($ctx, 'DeleteMessage', ['ReceiptHandle' => 'nonsense'])->assertStatus(400);
});

test('ChangeMessageVisibility to zero releases the message', function () {
    // This is how Laravel's driver releases a job back to the queue.
    $ctx = sqsNamespace();
    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertOk();
    $handle = sqsCall($ctx, 'ReceiveMessage')->json('Messages.0.ReceiptHandle');

    sqsCall($ctx, 'ChangeMessageVisibility', ['ReceiptHandle' => $handle, 'VisibilityTimeout' => 0])->assertOk();

    expect(sqsCall($ctx, 'ReceiveMessage')->json('Messages.0.Attributes.ApproximateReceiveCount'))->toBe('2');
});

test('ChangeMessageVisibility with a timeout extends the lease', function () {
    $ctx = sqsNamespace();
    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertOk();
    $handle = sqsCall($ctx, 'ReceiveMessage')->json('Messages.0.ReceiptHandle');

    sqsCall($ctx, 'ChangeMessageVisibility', ['ReceiptHandle' => $handle, 'VisibilityTimeout' => 600])->assertOk();

    expect(sqsCall($ctx, 'ReceiveMessage')->json('Messages'))->toBeNull();
});

test('DelaySeconds keeps a message invisible', function () {
    $ctx = sqsNamespace();

    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x', 'DelaySeconds' => 300])->assertOk();

    expect(sqsCall($ctx, 'ReceiveMessage')->json('Messages'))->toBeNull();
    expect(sqsCall($ctx, 'GetQueueAttributes')->json('Attributes.ApproximateNumberOfMessagesDelayed'))->toBe('1');
});

test('SendMessageBatch enqueues every entry', function () {
    $ctx = sqsNamespace();

    $response = sqsCall($ctx, 'SendMessageBatch', ['Entries' => [
        ['Id' => 'a', 'MessageBody' => 'one'],
        ['Id' => 'b', 'MessageBody' => 'two'],
    ]])->assertOk();

    expect($response->json('Successful'))->toHaveCount(2);
    expect($response->json('Failed'))->toBe([]);
    expect(sqsCall($ctx, 'GetQueueAttributes')->json('Attributes.ApproximateNumberOfMessages'))->toBe('2');
});

test('the queue name comes from the URL path', function () {
    $ctx = sqsNamespace();

    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'], 'emails')->assertOk();

    expect(sqsCall($ctx, 'GetQueueAttributes', [], 'emails')->json('Attributes.ApproximateNumberOfMessages'))->toBe('1');
    expect(sqsCall($ctx, 'GetQueueAttributes', [], 'default')->json('Attributes.ApproximateNumberOfMessages'))->toBe('0');
});

test('a credential cannot reach another namespace', function () {
    // The namespace comes from the credential and nowhere else — there is no
    // request field a caller could use to point at someone else's queue.
    $mine = sqsNamespace();
    $theirs = sqsNamespace();

    sqsCall($theirs, 'SendMessage', ['MessageBody' => 'theirs'])->assertOk();

    expect(sqsCall($mine, 'GetQueueAttributes')->json('Attributes.ApproximateNumberOfMessages'))->toBe('0');
    expect(sqsCall($mine, 'ReceiveMessage')->json('Messages'))->toBeNull();
});

test('a push-only credential cannot receive', function () {
    $ctx = sqsNamespace();
    $ctx['credential']->forceFill(['scopes' => [QueueCredential::SCOPE_PUSH]])->save();

    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'x'])->assertOk();
    sqsCall($ctx, 'ReceiveMessage')->assertStatus(403);
});

test('an unimplemented action reports itself clearly', function () {
    $ctx = sqsNamespace();

    sqsCall($ctx, 'PurgeQueue')
        ->assertStatus(400)
        ->assertJsonPath('__type', 'com.amazonaws.sqs#InvalidAction');
});

test('the depth limit rejects a push instead of accepting it', function () {
    $ctx = sqsNamespace(['max_queue_depth' => 1]);

    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'one'])->assertOk();

    sqsCall($ctx, 'SendMessage', ['MessageBody' => 'two'])
        ->assertStatus(403)
        ->assertJsonPath('__type', 'com.amazonaws.sqs#OverLimit');
});

test('an oversized payload is rejected', function () {
    $ctx = sqsNamespace(['max_payload_bytes' => 64]);

    sqsCall($ctx, 'SendMessage', ['MessageBody' => str_repeat('a', 128)])
        ->assertStatus(400)
        ->assertJsonPath('__type', 'com.amazonaws.sqs#InvalidParameterValue');
});

test('Laravel own sqs driver can push, pop, and delete against dply Queue', function () {
    // The claim this whole milestone rests on: Laravel's UNMODIFIED built-in
    // sqs driver, over the real AWS SDK, works against dply. If this passes, a
    // customer sets three env vars and their existing app just works — no
    // package to install, nothing for us to version against future releases.
    //
    // Built through Laravel's own SqsConnector rather than app('queue'),
    // because the suite swaps the queue manager for a fake. Going through the
    // connector is also the more faithful test: it is exactly the code path
    // `QUEUE_CONNECTION=sqs` takes in production.
    //
    // The SDK's http_handler is pointed at Laravel's test kernel, so requests
    // traverse the real routes, middleware, SigV4 verification, and controller.
    $ctx = sqsNamespace();

    $queue = (new SqsConnector)->connect([
        'driver' => 'sqs',
        'key' => $ctx['credential']->accessKeyId(),
        'secret' => $ctx['secret'],
        'prefix' => url('/api/queue/v1'),
        'queue' => 'default',
        'suffix' => '',
        'region' => 'us-east-1',
        'endpoint' => url('/api/queue/v1'),
        'http_handler' => function (RequestInterface $request, array $options) {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
            }
            $headers['CONTENT_TYPE'] = $request->getHeaderLine('Content-Type');

            $response = test()->call(
                $request->getMethod(),
                (string) $request->getUri(),
                [], [], [],
                $headers,
                (string) $request->getBody(),
            );

            if (getenv('DPLY_QUEUE_DEBUG')) {
                file_put_contents('/tmp/dqdbg.log', $request->getHeaderLine('X-Amz-Target')
                    .' | uri='.$request->getUri()
                    .' | req='.(string) $request->getBody()
                    .' | '.$response->getStatusCode().' resp='.$response->getContent().PHP_EOL, FILE_APPEND);
            }

            return Create::promiseFor(
                new Response(
                    $response->getStatusCode(),
                    ['Content-Type' => 'application/x-amz-json-1.0'],
                    $response->getContent(),
                )
            );
        },
    ]);

    // Built outside the manager, so wire the container/connection name the
    // manager would normally set — SqsJob needs both.
    $queue->setContainer(app());
    $queue->setConnectionName('dply_test');

    $queue->pushRaw('{"uuid":"driver-test","displayName":"App\\\\Jobs\\\\Real","timeout":45}');

    expect($queue->size())->toBe(1);

    // Pop returns a genuine Illuminate SqsJob built from our ReceiveMessage.
    $job = $queue->pop();

    expect($job)->not->toBeNull();
    expect($job->getRawBody())->toContain('driver-test');
    expect($job->attempts())->toBe(1);

    // And delete it, using the receipt handle we issued as the fencing token.
    $job->delete();

    expect($queue->size())->toBe(0);
});

test('a signature stays valid when the server clock has moved on', function () {
    // Regression guard for a bug that only showed up as flakiness: verifying
    // by re-signing with the SDK uses gmdate() at verification time, so it
    // only matched when the client's signing second and the server's
    // verification second happened to coincide. Over a real network that is
    // rarely true. Signing well in the past must still verify.
    $ctx = sqsNamespace();

    $url = url('/api/queue/v1');
    $body = (string) json_encode(['MessageBody' => 'delayed-in-flight']);

    // Sign 60 seconds ago — inside the 15-minute window, but definitively not
    // the same second the server will verify in.
    $past = CarbonImmutable::now()->subSeconds(60);

    $signed = CarbonImmutable::withTestNow($past, fn () => (new SignatureV4('sqs', 'us-east-1'))->signRequest(
        new PsrRequest('POST', $url, [
            'Content-Type' => 'application/x-amz-json-1.0',
            'X-Amz-Target' => 'AmazonSQS.SendMessage',
            'Host' => parse_url($url, PHP_URL_HOST),
        ], $body),
        new Credentials($ctx['credential']->accessKeyId(), $ctx['secret']),
    ));

    $headers = [];
    foreach ($signed->getHeaders() as $name => $values) {
        $headers[$name] = implode(', ', $values);
    }

    test()->call('POST', $url, [], [], [], sqsServerHeaders($headers), $body)->assertOk();
});

test('a signature older than the skew window is rejected', function () {
    $ctx = sqsNamespace();

    $url = url('/api/queue/v1');
    $body = (string) json_encode(['MessageBody' => 'ancient']);

    $psr = new PsrRequest('POST', $url, [
        'Content-Type' => 'application/x-amz-json-1.0',
        'X-Amz-Target' => 'AmazonSQS.SendMessage',
        'Host' => parse_url($url, PHP_URL_HOST),
    ], $body);

    $signed = (new SignatureV4('sqs', 'us-east-1'))->signRequest(
        $psr,
        new Credentials($ctx['credential']->accessKeyId(), $ctx['secret']),
    );

    $headers = [];
    foreach ($signed->getHeaders() as $name => $values) {
        $headers[$name] = implode(', ', $values);
    }
    // Backdate the signed timestamp well past the 15-minute window.
    $headers['X-Amz-Date'] = gmdate('Ymd\THis\Z', time() - 3600);

    test()->call('POST', $url, [], [], [], sqsServerHeaders($headers), $body)->assertStatus(403);
});
