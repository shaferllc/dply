<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Cross-host live build log stream.
 *
 * Edge builds run on worker boxes; the Build Journey Livewire UI runs on
 * web. Local `build.log` paths are invisible across that split, so the
 * runner also appends chunks here (shared Redis) for the poller to read.
 *
 * In testing (or when Redis is unavailable) falls back to the default
 * Cache store so unit tests don't need a live Redis.
 */
final class EdgeLiveBuildLog
{
    private const MAX_BYTES = 2_000_000;

    private const TTL_SECONDS = 6 * 3600;

    public static function redisKey(string $deploymentId): string
    {
        return 'edge:build-log:'.$deploymentId;
    }

    public static function append(string $deploymentId, string $chunk): void
    {
        if ($chunk === '' || $deploymentId === '') {
            return;
        }

        if (self::preferCacheStore()) {
            self::appendViaCache($deploymentId, $chunk);

            return;
        }

        try {
            $redis = Redis::connection();
            $key = self::redisKey($deploymentId);
            $redis->append($key, $chunk);
            $redis->expire($key, self::TTL_SECONDS);

            $len = (int) $redis->strlen($key);
            if ($len > self::MAX_BYTES) {
                $keep = self::MAX_BYTES - 64;
                $tail = (string) $redis->getrange($key, -$keep, -1);
                $redis->set($key, "… (older lines trimmed) …\n".$tail);
                $redis->expire($key, self::TTL_SECONDS);
            }
        } catch (Throwable $e) {
            report($e);
            self::appendViaCache($deploymentId, $chunk);
        }
    }

    /**
     * @return array{body: string, offset: int, exists: bool}
     */
    public static function readSince(string $deploymentId, int $offset, int $maxBytes = 32_000): array
    {
        if ($deploymentId === '') {
            return ['body' => '', 'offset' => $offset, 'exists' => false];
        }

        if (self::preferCacheStore()) {
            return self::readViaCache($deploymentId, $offset, $maxBytes);
        }

        try {
            $redis = Redis::connection();
            $key = self::redisKey($deploymentId);
            $len = (int) $redis->strlen($key);
            if ($len <= 0) {
                // Redis empty — try cache fallback (e.g. prior Redis failure).
                return self::readViaCache($deploymentId, $offset, $maxBytes);
            }
            if ($len <= $offset) {
                return ['body' => '', 'offset' => $offset, 'exists' => true];
            }

            $end = min($len - 1, $offset + max(1, $maxBytes) - 1);
            $body = (string) $redis->getrange($key, $offset, $end);

            return [
                'body' => $body,
                'offset' => $offset + strlen($body),
                'exists' => true,
            ];
        } catch (Throwable $e) {
            report($e);

            return self::readViaCache($deploymentId, $offset, $maxBytes);
        }
    }

    public static function clear(string $deploymentId): void
    {
        if ($deploymentId === '') {
            return;
        }

        Cache::forget(self::cacheKey($deploymentId));

        if (self::preferCacheStore()) {
            return;
        }

        try {
            Redis::connection()->del(self::redisKey($deploymentId));
        } catch (Throwable) {
            // ignore
        }
    }

    private static function preferCacheStore(): bool
    {
        return app()->environment('testing')
            && ! filter_var(config('edge.build.live_log_force_redis', false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function cacheKey(string $deploymentId): string
    {
        return self::redisKey($deploymentId);
    }

    private static function appendViaCache(string $deploymentId, string $chunk): void
    {
        $key = self::cacheKey($deploymentId);
        $existing = (string) Cache::get($key, '');
        $combined = $existing.$chunk;
        if (strlen($combined) > self::MAX_BYTES) {
            $combined = "… (older lines trimmed) …\n".substr($combined, -self::MAX_BYTES + 64);
        }
        Cache::put($key, $combined, now()->addSeconds(self::TTL_SECONDS));
    }

    /**
     * @return array{body: string, offset: int, exists: bool}
     */
    private static function readViaCache(string $deploymentId, int $offset, int $maxBytes): array
    {
        $bodyFull = Cache::get(self::cacheKey($deploymentId));
        if (! is_string($bodyFull) || $bodyFull === '') {
            return ['body' => '', 'offset' => $offset, 'exists' => false];
        }

        $len = strlen($bodyFull);
        if ($len <= $offset) {
            return ['body' => '', 'offset' => $offset, 'exists' => true];
        }

        $body = substr($bodyFull, $offset, max(1, $maxBytes));

        return [
            'body' => $body === false ? '' : $body,
            'offset' => $offset + ($body === false ? 0 : strlen($body)),
            'exists' => true,
        ];
    }
}
