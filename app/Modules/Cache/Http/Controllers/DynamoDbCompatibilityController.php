<?php

declare(strict_types=1);

namespace App\Modules\Cache\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceCredential;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Services\PostgresCacheStore;
use App\Modules\Cache\Support\CacheItem;
use App\Modules\Cache\Support\CacheRequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The DynamoDB-compatible surface of dply Cache.
 *
 * Speaks AWS JSON 1.0 — POST, `X-Amz-Target: DynamoDB_20120810.<Action>`, JSON
 * body, SigV4 — which is what the AWS SDK's DynamoDB client sends, and
 * therefore what `Illuminate\Cache\DynamoDbStore` sends. A customer sets six env
 * vars and their existing app works: there is no package to install and nothing
 * for us to version against future Laravel releases.
 *
 * Same play as dply Queue, which impersonates SQS, and Realtime, which shipped
 * no SDK by speaking the Pusher protocol.
 *
 * ## Scope
 *
 * Six actions, because `DynamoDbStore`'s entire API surface is six calls. This
 * is NOT a DynamoDB implementation and does not try to be — the conditional and
 * update expressions below are matched against the *specific literal forms the
 * framework emits*, not parsed. An unrecognised expression is a clean
 * ValidationException rather than a partial evaluation, because silently
 * mis-evaluating a condition on a lock is far worse than refusing it.
 *
 * `flush()` is deliberately absent: the driver throws for it, since DynamoDB
 * cannot truncate a table. dply owns this store, so flushing lives in the
 * dashboard instead.
 *
 * See docs/adr/dply-cache.md, decisions 2 and 14.
 */
class DynamoDbCompatibilityController extends Controller
{
    /** DynamoDB's own BatchGetItem ceiling. */
    private const MAX_BATCH_GET = 100;

    /** DynamoDB's own BatchWriteItem ceiling. */
    private const MAX_BATCH_WRITE = 25;

    public function __construct(private readonly PostgresCacheStore $store) {}

    /**
     * Decoded request body.
     *
     * Read explicitly rather than through `$request->input()`: the AWS JSON
     * protocol sends `Content-Type: application/x-amz-json-1.0`, which Laravel
     * does not recognise as JSON, so the input bag would be empty and every
     * field would silently read as its default.
     *
     * @var array<string, mixed>
     */
    private array $body = [];

    public function __invoke(Request $request): JsonResponse
    {
        $context = $request->attributes->get('cache_context');

        if (! $context instanceof CacheRequestContext) {
            return $this->error('UnrecognizedClientException', 'Unauthenticated.');
        }

        $decoded = json_decode($request->getContent(), true);
        $this->body = is_array($decoded) ? $decoded : [];

        return match ($this->action($request)) {
            'GetItem' => $this->getItem($context),
            'BatchGetItem' => $this->batchGetItem($context),
            'PutItem' => $this->putItem($context),
            'BatchWriteItem' => $this->batchWriteItem($context),
            'UpdateItem' => $this->updateItem($context),
            'DeleteItem' => $this->deleteItem($context),
            default => $this->error(
                'UnknownOperationException',
                'dply Cache does not implement '.($this->action($request) ?: 'this operation').'.',
            ),
        };
    }

    // ---------------------------------------------------------------- reads

    private function getItem(CacheRequestContext $context): JsonResponse
    {
        $cache = $this->table($context, (string) $this->field('TableName', ''), ServiceCredential::SCOPE_READ);

        if ($cache instanceof JsonResponse) {
            return $cache;
        }

        $key = $this->keyFrom((array) $this->field('Key', []));

        if ($key === null) {
            return $this->error('ValidationException', 'The provided key element does not match the schema.');
        }

        $item = $this->store->get($cache, $key);

        // A miss is an empty 200, exactly as DynamoDB answers it — the driver
        // checks `isset($response['Item'])`.
        return $this->ok($item === null ? [] : ['Item' => $this->itemPayload($item)]);
    }

    private function batchGetItem(CacheRequestContext $context): JsonResponse
    {
        $requestItems = (array) $this->field('RequestItems', []);
        $responses = [];

        foreach ($requestItems as $tableName => $spec) {
            $cache = $this->table($context, (string) $tableName, ServiceCredential::SCOPE_READ);

            if ($cache instanceof JsonResponse) {
                return $cache;
            }

            $keys = [];
            foreach ((array) (is_array($spec) ? ($spec['Keys'] ?? []) : []) as $keyMap) {
                $key = $this->keyFrom((array) $keyMap);
                if ($key !== null) {
                    $keys[] = $key;
                }
            }

            if (count($keys) > self::MAX_BATCH_GET) {
                return $this->error('ValidationException', 'Too many items requested for the BatchGetItem call.');
            }

            $responses[(string) $tableName] = array_map(
                fn (CacheItem $item): array => $this->itemPayload($item),
                array_values($this->store->many($cache, $keys)),
            );
        }

        // `UnprocessedKeys` is always empty: this store either answers a batch
        // or fails it. The driver reads the key and would loop forever on a
        // partial response it cannot make progress against.
        return $this->ok(['Responses' => $responses, 'UnprocessedKeys' => (object) []]);
    }

    // --------------------------------------------------------------- writes

    private function putItem(CacheRequestContext $context): JsonResponse
    {
        $cache = $this->table($context, (string) $this->field('TableName', ''), ServiceCredential::SCOPE_WRITE);

        if ($cache instanceof JsonResponse) {
            return $cache;
        }

        $item = $this->itemFrom((array) $this->field('Item', []));

        if ($item === null) {
            return $this->error('ValidationException', 'The provided item does not match the schema.');
        }

        if (($tooBig = $this->rejectOversizedOrOverQuota($cache, $item)) !== null) {
            return $tooBig;
        }

        $condition = trim((string) $this->field('ConditionExpression', ''));

        // The only conditional PutItem the framework emits is `add()`, which is
        // also `DynamoDbLock::acquire()`.
        if ($condition !== '') {
            if ($condition !== 'attribute_not_exists(#key) OR #expires_at < :now') {
                return $this->error('ValidationException', 'dply Cache does not support this ConditionExpression.');
            }

            if (! $this->store->putIfAbsent($cache, $item)) {
                return $this->error(
                    'ConditionalCheckFailedException',
                    'The conditional request failed.',
                );
            }

            return $this->ok([]);
        }

        $this->store->put($cache, $item);

        return $this->ok([]);
    }

    private function batchWriteItem(CacheRequestContext $context): JsonResponse
    {
        $requestItems = (array) $this->field('RequestItems', []);

        foreach ($requestItems as $tableName => $requests) {
            $cache = $this->table($context, (string) $tableName, ServiceCredential::SCOPE_WRITE);

            if ($cache instanceof JsonResponse) {
                return $cache;
            }

            $requests = (array) $requests;

            if (count($requests) > self::MAX_BATCH_WRITE) {
                return $this->error('ValidationException', 'Too many items requested for the BatchWriteItem call.');
            }

            foreach ($requests as $entry) {
                $entry = (array) $entry;

                if (isset($entry['DeleteRequest'])) {
                    $key = $this->keyFrom((array) (((array) $entry['DeleteRequest'])['Key'] ?? []));
                    if ($key !== null) {
                        $this->store->delete($cache, $key);
                    }

                    continue;
                }

                $item = $this->itemFrom((array) (((array) ($entry['PutRequest'] ?? []))['Item'] ?? []));

                if ($item === null) {
                    return $this->error('ValidationException', 'The provided item does not match the schema.');
                }

                if (($rejected = $this->rejectOversizedOrOverQuota($cache, $item)) !== null) {
                    return $rejected;
                }

                $this->store->put($cache, $item);
            }
        }

        return $this->ok(['UnprocessedItems' => (object) []]);
    }

    /**
     * The three update forms the framework emits: numeric add (increment and
     * decrement), and expiry moves (touch and lock refresh).
     *
     * Matched literally, not parsed. A general expression evaluator here would
     * be a large amount of security-relevant surface built to serve four known
     * strings.
     */
    private function updateItem(CacheRequestContext $context): JsonResponse
    {
        $cache = $this->table($context, (string) $this->field('TableName', ''), ServiceCredential::SCOPE_WRITE);

        if ($cache instanceof JsonResponse) {
            return $cache;
        }

        $key = $this->keyFrom((array) $this->field('Key', []));

        if ($key === null) {
            return $this->error('ValidationException', 'The provided key element does not match the schema.');
        }

        $update = $this->normalizeExpression((string) $this->field('UpdateExpression', ''));
        $values = (array) $this->field('ExpressionAttributeValues', []);

        // increment(): SET #value = #value + :amount
        // decrement(): SET #value = #value - :amount
        if ($update === 'SET #value = #value + :amount' || $update === 'SET #value = #value - :amount') {
            $amount = (int) $this->scalar($values[':amount'] ?? null);
            $signed = str_contains($update, '-') ? -$amount : $amount;

            $result = $this->store->addToValue($cache, $key, $signed);

            if ($result === null) {
                return $this->error('ConditionalCheckFailedException', 'The conditional request failed.');
            }

            return $this->ok(['Attributes' => ['value' => ['N' => (string) $result]]]);
        }

        // touch(): SET #expiry = :expiry
        // refreshIfOwned(): SET #expires_at = :expires_at, conditional on #value
        if ($update === 'SET #expires_at = :expires_at' || $update === 'SET #expiry = :expiry') {
            $expiry = (int) $this->scalar($values[':expires_at'] ?? $values[':expiry'] ?? null);

            // `:owner` present means refreshIfOwned — the expiry may only move
            // if the caller still holds the lock. Comparing here rather than
            // reading first is what keeps the refresh atomic.
            $owner = isset($values[':owner']) ? (string) $this->scalar($values[':owner']) : null;

            if (! $this->store->setExpiry($cache, $key, $expiry, $owner)) {
                return $this->error('ConditionalCheckFailedException', 'The conditional request failed.');
            }

            return $this->ok([]);
        }

        return $this->error('ValidationException', 'dply Cache does not support this UpdateExpression.');
    }

    private function deleteItem(CacheRequestContext $context): JsonResponse
    {
        $cache = $this->table($context, (string) $this->field('TableName', ''), ServiceCredential::SCOPE_WRITE);

        if ($cache instanceof JsonResponse) {
            return $cache;
        }

        $key = $this->keyFrom((array) $this->field('Key', []));

        if ($key === null) {
            return $this->error('ValidationException', 'The provided key element does not match the schema.');
        }

        $this->store->delete($cache, $key);

        return $this->ok([]);
    }

    // --------------------------------------------------------------- tenancy

    /**
     * Resolve `TableName` to a cache this credential may use.
     *
     * `TableName` selects; the grant authorises. It is client-controlled, so
     * trusting it to choose the tenant would let anyone read any cache by
     * editing one line of their `.env`.
     *
     * "Not found" and "not yours" return the identical
     * ResourceNotFoundException. Distinguishing them would turn this endpoint
     * into an enumeration oracle — "this cache exists but is not yours" is
     * information given away for free. See docs/adr/dply-cache.md, decision 14.
     */
    private function table(CacheRequestContext $context, string $tableName, string $scope): ManagedCache|JsonResponse
    {
        $tableName = trim($tableName);

        if ($tableName === '' || ! $context->allows($tableName, $scope)) {
            return $this->notFound();
        }

        $cache = ManagedCache::query()->find($tableName);

        if (! $cache instanceof ManagedCache || ! $cache->isReachable()) {
            return $this->notFound();
        }

        return $cache;
    }

    /**
     * Refuse writes that are individually too large, or that land on a cache
     * already at its quota.
     *
     * `ValidationException`, deliberately NOT
     * `ProvisionedThroughputExceededException`. The latter is in the AWS SDK's
     * *retryable* set, so a customer at quota would get silent exponential
     * backoff on every write and observe hangs rather than failures. A quota
     * that manifests as latency is not a quota.
     */
    private function rejectOversizedOrOverQuota(ManagedCache $cache, CacheItem $item): ?JsonResponse
    {
        $maxItem = (int) config('cache_service.shared.max_item_bytes', 262_144);

        if ($item->byteSize() > $maxItem) {
            return $this->error(
                'ValidationException',
                'Item size has exceeded the maximum allowed size of '.$maxItem.' bytes.',
            );
        }

        if ($this->store->usage($cache->id)->isOverQuota($cache->quotaBytes())) {
            return $this->error(
                'ValidationException',
                'This cache has reached its storage quota of '.$cache->quotaBytes().' bytes. '
                .'Free space by letting keys expire, flush it from the dply dashboard, '
                .'or attach a dedicated cache.',
            );
        }

        return null;
    }

    // ------------------------------------------------------------- decoding

    /**
     * The single-attribute primary key, as `{"key": {"S": "..."}}`.
     *
     * The attribute name is not assumed to be `key`: it is configurable on the
     * store (`DYNAMODB_CACHE_KEY_ATTRIBUTE`), so the first string attribute is
     * taken instead of a hard-coded name.
     *
     * @param  array<string, mixed>  $map
     */
    private function keyFrom(array $map): ?string
    {
        foreach ($map as $attribute) {
            if (is_array($attribute) && isset($attribute['S'])) {
                return (string) $attribute['S'];
            }
        }

        return null;
    }

    /**
     * Decode an Item map into a {@see CacheItem}.
     *
     * The three attributes are identified by shape rather than by name, for the
     * same configurability reason as {@see keyFrom()}: the key is the string
     * attribute, the expiry is the numeric one that looks like a timestamp, and
     * the value is whatever is left.
     *
     * @param  array<string, mixed>  $map
     */
    private function itemFrom(array $map): ?CacheItem
    {
        $key = $this->keyFrom($map);

        if ($key === null) {
            return null;
        }

        $expiresAt = null;
        $value = null;
        $type = 'S';
        $seenKey = false;

        foreach ($map as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            if (! $seenKey && isset($attribute['S']) && (string) $attribute['S'] === $key) {
                $seenKey = true;

                continue;
            }

            if (isset($attribute['N']) && $expiresAt === null && $this->looksLikeTimestamp((string) $attribute['N'])) {
                $expiresAt = (int) $attribute['N'];

                continue;
            }

            $attributeType = array_key_first($attribute);

            if ($attributeType !== null) {
                $value = (string) $attribute[$attributeType];
                $type = (string) $attributeType;
            }
        }

        if ($expiresAt === null) {
            return null;
        }

        // Nothing lives forever in a free store. `Cache::forever()` becomes a
        // very distant expiry on this driver, and TTL-only storage would never
        // reclaim it.
        $maxTtl = (int) config('cache_service.shared.max_ttl_seconds', 60 * 60 * 24 * 30);
        $expiresAt = min($expiresAt, now()->getTimestamp() + $maxTtl);

        return new CacheItem($key, $value, $type, $expiresAt);
    }

    /**
     * Whether a numeric attribute is plausibly the expiry rather than the value.
     *
     * `Cache::put('hits', 1)` and the expiry are both `{"N": ...}`, so the two
     * are only distinguishable by magnitude. Anything at or beyond the current
     * second is an expiry; a counter would have to be a ten-digit number to be
     * confused for one, and such a value is still stored correctly — it would
     * merely be read as the expiry of an item that has none, which
     * {@see itemFrom()} rejects rather than guessing at.
     */
    private function looksLikeTimestamp(string $number): bool
    {
        return ctype_digit($number) && (int) $number >= now()->subYear()->getTimestamp();
    }

    /** The scalar inside an AttributeValue, whatever its discriminator. */
    private function scalar(mixed $attribute): string
    {
        if (! is_array($attribute)) {
            return '';
        }

        $type = array_key_first($attribute);

        return $type === null ? '' : (string) $attribute[$type];
    }

    /** Collapse incidental whitespace so expression matching is not brittle. */
    private function normalizeExpression(string $expression): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $expression));
    }

    private function action(Request $request): string
    {
        $target = (string) $request->header('X-Amz-Target', '');

        return str_contains($target, '.') ? substr($target, strrpos($target, '.') + 1) : '';
    }

    private function field(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    private function itemPayload(CacheItem $item): array
    {
        return [
            'key' => ['S' => $item->key],
            'value' => $item->toAttributeValue(),
            'expires_at' => ['N' => (string) $item->expiresAt],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ok(array $payload): JsonResponse
    {
        return response()->json($payload, 200, ['Content-Type' => 'application/x-amz-json-1.0']);
    }

    private function notFound(): JsonResponse
    {
        return $this->error('ResourceNotFoundException', 'Requested resource not found.');
    }

    private function error(string $code, string $message): JsonResponse
    {
        return response()->json([
            '__type' => 'com.amazonaws.dynamodb.v20120810#'.$code,
            'message' => $message,
        ], 400, ['Content-Type' => 'application/x-amz-json-1.0']);
    }
}
