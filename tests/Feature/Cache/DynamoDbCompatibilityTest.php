<?php

declare(strict_types=1);

namespace Tests\Feature\Cache\DynamoDbCompatibilityTest;

use App\Models\Organization;
use App\Models\ServiceCredential;
use App\Modules\Cache\Actions\MintCacheCredential;
use App\Modules\Cache\Models\ManagedCache;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;

uses(RefreshDatabase::class);

/**
 * dply Cache, exercised through Laravel's OWN cache driver.
 *
 * This is the test that makes the compatibility claim mean something. Nothing
 * here hand-builds a request: `Illuminate\Cache\DynamoDbStore` and the AWS SDK
 * produce the wire format, and the SDK's http_handler is pointed at Laravel's
 * test kernel so every call traverses the real route, middleware, SigV4
 * verification, and controller. If the endpoint drifts from what the framework
 * actually sends, these fail — which is the entire point of choosing a protocol
 * the framework already speaks (docs/adr/dply-cache.md, decision 2).
 *
 * @return array{cache: ManagedCache, credential: ServiceCredential, secret: string}
 */
function cacheCtx(array $attributes = []): array
{
    $org = Organization::factory()->create();

    $cache = ManagedCache::query()->create(array_merge([
        'organization_id' => $org->id,
        'name' => 'primary',
        'tier' => ManagedCache::TIER_SHARED,
        'status' => ManagedCache::STATUS_ACTIVE,
    ], $attributes));

    $minted = (new MintCacheCredential)->handle($cache, 'test');

    return [
        'cache' => $cache->refresh(),
        'credential' => $minted['credential'],
        'secret' => $minted['plaintext'],
    ];
}

/** A real Laravel cache repository backed by the real driver, aimed at dply. */
function cacheRepo(array $ctx, ?string $table = null): Repository
{
    $client = new DynamoDbClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'endpoint' => url('/api/cache/v1'),
        'credentials' => [
            'key' => $ctx['credential']->accessKeyId(),
            'secret' => $ctx['secret'],
        ],
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

            $psr = new Response(
                $response->getStatusCode(),
                ['Content-Type' => 'application/x-amz-json-1.0'],
                $response->getContent(),
            );

            /*
             * Mimic Guzzle's error contract, and do not skip this.
             *
             * Aws\WrappedHttpHandler treats ANY fulfilled promise as a success
             * and only turns a *rejected* one into an AwsException. The real
             * handler gets that for free because Guzzle's `http_errors` raises
             * on 4xx, which `GuzzleHandler` catches and converts into exactly
             * this rejection shape.
             *
             * A test handler that fulfils on a 400 therefore makes every error
             * path silently pass: ConditionalCheckFailed reads as success, so
             * `add()` always returns true and a lock is never actually held.
             */
            if ($psr->getStatusCode() >= 400) {
                return new RejectedPromise([
                    'exception' => new BadResponseException('HTTP error', $request, $psr),
                    'connection_error' => false,
                    'response' => $psr,
                ]);
            }

            return Create::promiseFor($psr);
        },
    ]);

    return new Repository(new DynamoDbStore($client, $table ?? $ctx['cache']->id));
}

test('a value round-trips through the real driver', function () {
    $repo = cacheRepo(cacheCtx());

    expect($repo->get('missing'))->toBeNull();

    $repo->put('greeting', 'hello', 60);

    expect($repo->get('greeting'))->toBe('hello');
});

test('types survive the round trip', function () {
    $repo = cacheRepo(cacheCtx());

    // The AttributeValue discriminator is carried end to end precisely so an
    // int does not come back a string.
    $repo->put('int', 42, 60);
    $repo->put('array', ['a' => 1], 60);
    $repo->put('bool', true, 60);

    expect($repo->get('int'))->toBe(42);
    expect($repo->get('array'))->toBe(['a' => 1]);
    expect($repo->get('bool'))->toBeTrue();
});

test('an expired value is not returned even before it is swept', function () {
    $repo = cacheRepo(cacheCtx());

    // A 60s TTL travelled past, rather than a 1s TTL slept through: with a
    // one-second window the real time spent on the write's HTTPS round trip
    // can cross the expiry before the first read, which made this flake.
    // travel() moves the clock deterministically, so the window can be as
    // wide as we like.
    $repo->put('short', 'v', 60);
    expect($repo->get('short'))->toBe('v');

    // Nothing sweeps here. Expiry is enforced on READ, so a lagging sweeper
    // can never surface a stale value.
    $this->travel(120)->seconds();

    expect($repo->get('short'))->toBeNull();
});

test('forget removes a value', function () {
    $repo = cacheRepo(cacheCtx());

    $repo->put('gone', 'v', 60);
    $repo->forget('gone');

    expect($repo->get('gone'))->toBeNull();
});

test('many and putMany use the batch operations', function () {
    $repo = cacheRepo(cacheCtx());

    $repo->putMany(['a' => 1, 'b' => 2, 'c' => 3], 60);

    expect($repo->many(['a', 'b', 'c', 'absent']))
        ->toBe(['a' => 1, 'b' => 2, 'c' => 3, 'absent' => null]);
});

test('add is atomic and only the first caller wins', function () {
    $repo = cacheRepo(cacheCtx());

    expect($repo->add('once', 'first', 60))->toBeTrue();
    expect($repo->add('once', 'second', 60))->toBeFalse();
    expect($repo->get('once'))->toBe('first');
});

test('increment and decrement are atomic', function () {
    $repo = cacheRepo(cacheCtx());

    $repo->put('hits', 10, 60);

    expect($repo->increment('hits'))->toBe(11);
    expect($repo->increment('hits', 5))->toBe(16);
    expect($repo->decrement('hits', 6))->toBe(10);
    expect($repo->get('hits'))->toBe(10);
});

test('incrementing a missing key fails the condition rather than creating it', function () {
    $repo = cacheRepo(cacheCtx());

    expect($repo->increment('never-set'))->toBeFalse();
});

test('a lock is held against a second owner and released to the next', function () {
    // The reason the whole product exists: on a function or a multi-replica
    // container, Cache::lock() has nowhere real to coordinate, so
    // ShouldBeUnique / WithoutOverlapping / RateLimited silently no-op.
    $repo = cacheRepo(cacheCtx());

    $first = $repo->lock('deploy', 60);
    $second = $repo->lock('deploy', 60);

    expect($first->get())->toBeTrue();
    expect($second->get())->toBeFalse();

    $first->release();

    expect($second->get())->toBeTrue();
});

test('a lock expires so a crashed holder cannot wedge it forever', function () {
    $repo = cacheRepo(cacheCtx());

    expect($repo->lock('wedged', 60)->get())->toBeTrue();

    $this->travel(120)->seconds();

    expect($repo->lock('wedged', 60)->get())->toBeTrue();
});

test('one cache cannot read another', function () {
    $mine = cacheCtx();
    $theirs = cacheCtx();

    cacheRepo($theirs)->put('secret', 'theirs', 60);

    // My credential, their table id. The grant map is the authority, so this
    // is a miss no matter what the client puts in DYNAMODB_CACHE_TABLE.
    $crossed = cacheRepo($mine, $theirs['cache']->id);

    expect(fn () => $crossed->get('secret'))->toThrow(DynamoDbException::class);
});

test('an unknown table is refused the same way a forbidden one is', function () {
    // Identical ResourceNotFoundException for "does not exist" and "not
    // yours" — distinguishing them would make this an enumeration oracle.
    $ctx = cacheCtx();

    $unknown = cacheRepo($ctx, '01ZZZZZZZZZZZZZZZZZZZZZZZZ');

    expect(fn () => $unknown->get('x'))
        ->toThrow(DynamoDbException::class, 'Requested resource not found');
});

test('a write past the quota is refused with a non-retryable error', function () {
    // ValidationException, never ProvisionedThroughputExceededException: the
    // latter is in the SDK's retryable set, so a customer at quota would see
    // exponential backoff and hangs instead of a failure.
    $ctx = cacheCtx(['quota_bytes' => 64]);
    $repo = cacheRepo($ctx);

    $repo->put('fills-it', str_repeat('x', 200), 60);

    expect($ctx['cache']->usage()->isOverQuota($ctx['cache']->quotaBytes()))->toBeTrue();

    expect(fn () => $repo->put('next', 'v', 60))
        ->toThrow(DynamoDbException::class, 'storage quota');
});

test('an oversized item is refused', function () {
    config(['cache_service.shared.max_item_bytes' => 128]);

    $repo = cacheRepo(cacheCtx());

    expect(fn () => $repo->put('huge', str_repeat('x', 500), 60))
        ->toThrow(DynamoDbException::class, 'maximum allowed size');
});

test('quota accounting follows writes and deletes', function () {
    $ctx = cacheCtx();
    $repo = cacheRepo($ctx);

    $repo->put('a', str_repeat('x', 100), 60);

    expect($ctx['cache']->usage()->residentBytes)->toBeGreaterThan(100);
    expect($ctx['cache']->usage()->itemCount)->toBe(1);

    $repo->forget('a');

    expect($ctx['cache']->usage()->residentBytes)->toBe(0);
    expect($ctx['cache']->usage()->itemCount)->toBe(0);
});

test('a revoked credential stops working', function () {
    $ctx = cacheCtx();
    $repo = cacheRepo($ctx);

    $repo->put('k', 'v', 60);

    $ctx['credential']->forceFill(['revoked_at' => now()])->save();

    expect(fn () => $repo->get('k'))->toThrow(DynamoDbException::class);
});

test('an unsupported operation is reported clearly rather than half-served', function () {
    $ctx = cacheCtx();

    $response = test()->call('POST', url('/api/cache/v1'), [], [], [], [
        'HTTP_X_AMZ_TARGET' => 'DynamoDB_20120810.Scan',
        'CONTENT_TYPE' => 'application/x-amz-json-1.0',
    ], (string) json_encode(['TableName' => $ctx['cache']->id]));

    // Unauthenticated here, so the auth layer answers first — the point is
    // that it is a parseable AWS envelope either way.
    expect($response->json('__type'))->toContain('com.amazonaws.dynamodb');
});

test('the sweep reclaims expired items and their quota', function () {
    $ctx = cacheCtx();
    $repo = cacheRepo($ctx);

    $repo->put('keep', 'v', 3600);
    $repo->put('drop', 'v', 60);

    expect($ctx['cache']->usage()->itemCount)->toBe(2);

    // Same reasoning as above: travel past a comfortable TTL rather than race
    // a one-second one.
    $this->travel(120)->seconds();

    // Already invisible to readers before anything is swept — the sweep
    // reclaims space, it does not decide correctness.
    expect($repo->get('drop'))->toBeNull();

    $this->artisan('dply:cache:sweep')->assertSuccessful();

    expect($ctx['cache']->usage()->itemCount)->toBe(1);
    expect($repo->get('keep'))->toBe('v');
});
