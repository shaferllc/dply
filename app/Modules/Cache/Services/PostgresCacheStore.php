<?php

declare(strict_types=1);

namespace App\Modules\Cache\Services;

use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Support\CacheItem;
use App\Modules\Cache\Support\CacheStoreIsolation;
use App\Modules\Cache\Support\CacheUsage;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The shared tier's storage engine.
 *
 * Deliberately expressed in DynamoDB's vocabulary — get / put / putIfAbsent /
 * addToValue / setExpiry / delete — rather than Laravel's, because the caller
 * is a protocol adapter, not the framework. Every conditional form here exists
 * because `Illuminate\Cache\DynamoDbStore` emits it; nothing is general.
 *
 * The conditional operations are single statements for the same reason
 * `PostgresQueueLockStore::acquire()` was: a check-then-set gap is not a
 * condition. `putIfAbsent()` in particular backs `DynamoDbLock::acquire()`, so
 * a read-then-write window there would be a lock that is not a lock.
 *
 * Expiry is enforced on READ, not merely by the sweeper. A lagging sweep must
 * never be able to surface a stale value; it reclaims space, it does not
 * decide correctness.
 */
class PostgresCacheStore
{
    private const TABLE = 'dply_cache_items';

    private const USAGE_TABLE = 'dply_cache_usage';

    /**
     * What this cache currently occupies.
     *
     * Maintained by statement-level triggers on the item table, so it stays
     * correct across the sweeper, a flush, and a customer write alike without
     * any of them having to remember to account for themselves.
     */
    public function usage(string $cacheId): CacheUsage
    {
        $row = $this->connection()
            ->table(self::USAGE_TABLE)
            ->where('cache_id', $cacheId)
            ->first();

        return $row === null
            ? CacheUsage::empty()
            : new CacheUsage((int) $row->resident_bytes, (int) $row->item_count);
    }

    /**
     * Usage for several caches at once, keyed by cache id.
     *
     * The index page renders a meter per cache; without this it would issue one
     * query per row.
     *
     * @param  list<string>  $cacheIds
     * @return array<string, CacheUsage>
     */
    public function usageFor(array $cacheIds): array
    {
        if ($cacheIds === []) {
            return [];
        }

        $usage = [];

        foreach ($this->connection()->table(self::USAGE_TABLE)->whereIn('cache_id', $cacheIds)->get() as $row) {
            $usage[(string) $row->cache_id] = new CacheUsage((int) $row->resident_bytes, (int) $row->item_count);
        }

        return $usage;
    }

    /**
     * Fetch one live item.
     */
    public function get(ManagedCache $cache, string $key): ?CacheItem
    {
        $row = $this->connection()
            ->table(self::TABLE)
            ->where('cache_id', $cache->id)
            ->where('key', $key)
            ->where('expires_at', '>', $this->now())
            ->first();

        return $row === null ? null : CacheItem::fromRow($row);
    }

    /**
     * Fetch many live items in one round trip.
     *
     * Backs `Cache::many()` via BatchGetItem — the one place a customer can
     * make N keys cost one request instead of N, which matters more here than
     * on a local Redis because each request is a full HTTPS round trip.
     *
     * @param  list<string>  $keys
     * @return array<string, CacheItem>  keyed by cache key; misses are absent
     */
    public function many(ManagedCache $cache, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $rows = $this->connection()
            ->table(self::TABLE)
            ->where('cache_id', $cache->id)
            ->whereIn('key', $keys)
            ->where('expires_at', '>', $this->now())
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[(string) $row->key] = CacheItem::fromRow($row);
        }

        return $items;
    }

    /**
     * Unconditional write. Overwrites whatever is there, live or expired.
     */
    public function put(ManagedCache $cache, CacheItem $item): void
    {
        $this->connection()->statement(
            'INSERT INTO '.self::TABLE.' (cache_id, key, value, value_type, expires_at, byte_size)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (cache_id, key) DO UPDATE
                SET value      = EXCLUDED.value,
                    value_type = EXCLUDED.value_type,
                    expires_at = EXCLUDED.expires_at,
                    byte_size  = EXCLUDED.byte_size',
            $this->bindings($cache, $item),
        );
    }

    /**
     * Write only if the key is absent or already expired.
     *
     * The DynamoDB condition `attribute_not_exists(#key) OR #expires_at < :now`
     * expressed as one statement. The `WHERE` on the DO UPDATE branch is what
     * makes it conditional: a live row matches nothing to update, so the
     * statement affects zero rows and the caller learns it lost.
     *
     * This is `DynamoDbLock::acquire()`. It must have no read-then-write gap.
     */
    public function putIfAbsent(ManagedCache $cache, CacheItem $item): bool
    {
        $affected = $this->connection()->affectingStatement(
            'INSERT INTO '.self::TABLE.' (cache_id, key, value, value_type, expires_at, byte_size)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (cache_id, key) DO UPDATE
                SET value      = EXCLUDED.value,
                    value_type = EXCLUDED.value_type,
                    expires_at = EXCLUDED.expires_at,
                    byte_size  = EXCLUDED.byte_size
              WHERE '.self::TABLE.'.expires_at <= ?',
            [...$this->bindings($cache, $item), $this->now()],
        );

        return $affected > 0;
    }

    /**
     * Atomic numeric add on a live row.
     *
     * `SET #value = #value + :amount` with
     * `attribute_exists(#key) AND #expires_at > :now`. Returns the new value,
     * or null when the condition failed — which the adapter reports as
     * ConditionalCheckFailed, the signal `increment()` turns into `false`.
     *
     * Rows whose value is not numeric are excluded by the regex rather than
     * allowed to raise: a cast error would surface to the customer as a 500,
     * where DynamoDB's own answer is a failed condition.
     */
    public function addToValue(ManagedCache $cache, string $key, int $amount): ?int
    {
        $rows = $this->connection()->select(
            'UPDATE '.self::TABLE.'
                SET value = ((value)::bigint + ?)::text,
                    byte_size = length(((value)::bigint + ?)::text) + length(key)
              WHERE cache_id = ?
                AND key = ?
                AND expires_at > ?
                AND value ~ \'^-?[0-9]+$\'
          RETURNING value',
            [$amount, $amount, $cache->id, $key, $this->now()],
        );

        return $rows === [] ? null : (int) $rows[0]->value;
    }

    /**
     * Move the expiry of a live row, optionally only when the value matches.
     *
     * Covers both `touch()` (no owner) and `refreshIfOwned()` (owner given) —
     * one statement, because the second is a lock refresh and a check-then-set
     * gap would let a lock be refreshed by someone who no longer holds it.
     */
    public function setExpiry(ManagedCache $cache, string $key, int $expiresAt, ?string $expectedValue = null): bool
    {
        $query = $this->connection()
            ->table(self::TABLE)
            ->where('cache_id', $cache->id)
            ->where('key', $key)
            ->where('expires_at', '>', $this->now());

        if ($expectedValue !== null) {
            $query->where('value', $expectedValue);
        }

        return $query->update(['expires_at' => $expiresAt]) > 0;
    }

    public function delete(ManagedCache $cache, string $key): void
    {
        $this->connection()
            ->table(self::TABLE)
            ->where('cache_id', $cache->id)
            ->where('key', $key)
            ->delete();
    }

    /**
     * Empty a cache.
     *
     * Not reachable from the driver — `DynamoDbStore::flush()` throws, because
     * DynamoDB genuinely cannot truncate a table. dply owns this store, so the
     * dashboard can do what the driver cannot. See docs/adr/dply-cache.md,
     * decision 11.
     */
    public function flush(ManagedCache $cache): int
    {
        $deleted = $this->connection()
            ->table(self::TABLE)
            ->where('cache_id', $cache->id)
            ->delete();

        // The delete trigger already zeroes the counter; dropping the row as
        // well keeps a deleted cache from leaving a usage record behind. Done
        // after the delete, never before — the trigger would recreate it.
        $this->connection()
            ->table(self::USAGE_TABLE)
            ->where('cache_id', $cache->id)
            ->delete();

        return $deleted;
    }

    /**
     * Reclaim expired rows for every cache, in bounded batches.
     *
     * Batched so one pass cannot hold a long transaction against the store,
     * and so the statement-level usage trigger aggregates over a batch rather
     * than the whole table.
     */
    public function sweep(int $batchSize, int $maxBatches): int
    {
        $deleted = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $affected = $this->connection()->affectingStatement(
                'DELETE FROM '.self::TABLE.'
                  WHERE ctid IN (
                        SELECT ctid FROM '.self::TABLE.'
                         WHERE expires_at <= ?
                         LIMIT '.$batchSize.'
                  )',
                [$this->now()],
            );

            $deleted += $affected;

            if ($affected < $batchSize) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * @return list<mixed>
     */
    private function bindings(ManagedCache $cache, CacheItem $item): array
    {
        return [
            $cache->id,
            $item->key,
            $item->value,
            $item->type,
            $item->expiresAt,
            $item->byteSize(),
        ];
    }

    private function now(): int
    {
        return now()->getTimestamp();
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(CacheStoreIsolation::CONNECTION);
    }
}
