<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Curated metadata for each cache engine surfaced in the workspace UI: display name, longer
 * description, homepage + docs URLs, license, maintainer, and a short "best for" hint. Lives
 * here (not in config) so the strings stay translatable via __() at the view layer.
 *
 * Keep these URLs to canonical first-party sources only — homepage and primary docs. We don't
 * want to ship users off to random blog posts that can rot.
 */
final class CacheEngineInfo
{
    /**
     * @return array<string, array{
     *   label: string,
     *   tagline: string,
     *   description: string,
     *   homepage_url: string,
     *   docs_url: string,
     *   license: string,
     *   maintainer: string,
     *   best_for: string,
     *   wire_protocol: string,
     *   first_released: string,
     * }>
     */
    public static function all(): array
    {
        return [
            'redis' => [
                'label' => 'Redis',
                'tagline' => __('The original in-memory data structure store.'),
                'description' => __('In-memory key-value store with rich data types (strings, hashes, lists, sets, sorted sets, streams, hyperloglogs). The reference implementation that every Redis-compatible engine targets. Single-threaded by default — predictable latency, easy to reason about.'),
                'homepage_url' => 'https://redis.io',
                'docs_url' => 'https://redis.io/docs/latest/',
                'license' => 'RSALv2 / SSPLv1 (since 7.4) — was BSD-3-Clause through 7.2',
                'maintainer' => 'Redis Ltd.',
                'best_for' => __('Caching, session storage, queues, rate limiting, pub/sub. Default pick for Laravel apps.'),
                'wire_protocol' => 'RESP (Redis Serialization Protocol)',
                'first_released' => '2009',
            ],
            'valkey' => [
                'label' => 'Valkey',
                'tagline' => __('Open-source fork of Redis, maintained by the Linux Foundation.'),
                'description' => __('A drop-in replacement for Redis 7.2 created after Redis Inc. relicensed Redis to RSALv2/SSPL. Wire-compatible with Redis clients; same RESP protocol; same commands. Backed by AWS, Google Cloud, Oracle, and others under the Linux Foundation umbrella.'),
                'homepage_url' => 'https://valkey.io',
                'docs_url' => 'https://valkey.io/topics/',
                'license' => 'BSD-3-Clause',
                'maintainer' => 'Linux Foundation (Valkey project)',
                'best_for' => __('Same as Redis. Pick this if you want BSD licensing or care about a multi-vendor governance model.'),
                'wire_protocol' => 'RESP (Redis Serialization Protocol)',
                'first_released' => '2024',
            ],
            'memcached' => [
                'label' => 'Memcached',
                'tagline' => __('Lightweight, slab-allocated key-value cache.'),
                'description' => __('Simple, multi-threaded in-memory cache focused on doing one thing well: storing string values keyed by string keys with TTL. No persistence, no rich data types, no pub/sub. Slab allocator avoids fragmentation; SO_REUSEPORT for parallel accept on multi-core hosts.'),
                'homepage_url' => 'https://memcached.org',
                'docs_url' => 'https://github.com/memcached/memcached/wiki',
                'license' => 'BSD-3-Clause',
                'maintainer' => 'Community (dormando et al.)',
                'best_for' => __('Pure caching workloads where you don\'t need data structures, persistence, or pub/sub. Lower per-key memory overhead than Redis-family engines.'),
                'wire_protocol' => __('Memcached text + binary protocol (not Redis-compatible)'),
                'first_released' => '2003',
            ],
            'keydb' => [
                'label' => 'KeyDB',
                'tagline' => __('Multi-threaded fork of Redis, originally from Snapchat.'),
                'description' => __('Drop-in replacement for Redis with multi-threaded I/O and active replication. Same RESP protocol, same commands, same on-wire compatibility. Trade-off vs Redis: higher throughput on multi-core boxes but more complex internals (locks, atomics).'),
                'homepage_url' => 'https://docs.keydb.dev',
                'docs_url' => 'https://docs.keydb.dev/docs/intro/',
                'license' => 'BSD-3-Clause',
                'maintainer' => 'Snap Inc. (acquired EQ Alpha Technology)',
                'best_for' => __('CPU-bound Redis workloads that saturate a single core. Active-replication multi-master setups.'),
                'wire_protocol' => 'RESP (Redis Serialization Protocol)',
                'first_released' => '2019',
            ],
            'dragonfly' => [
                'label' => 'Dragonfly',
                'tagline' => __('Modern in-memory store with Redis wire compatibility and lower memory overhead.'),
                'description' => __('Ground-up rewrite (not a fork) using a shared-nothing architecture: each thread owns a slice of the keyspace, no locks on the hot path. Implements the Redis and Memcached wire protocols. Claims significantly higher throughput and lower memory overhead than Redis on the same hardware.'),
                'homepage_url' => 'https://www.dragonflydb.io',
                'docs_url' => 'https://www.dragonflydb.io/docs',
                'license' => 'BSL 1.1 (converts to Apache 2.0 after 4 years)',
                'maintainer' => 'DragonflyDB Ltd.',
                'best_for' => __('High-throughput cache/queue workloads on big multi-core boxes where Redis becomes the bottleneck.'),
                'wire_protocol' => __('RESP + Memcached text protocol'),
                'first_released' => '2022',
            ],
        ];
    }

    /**
     * @return array{
     *   label: string,
     *   tagline: string,
     *   description: string,
     *   homepage_url: string,
     *   docs_url: string,
     *   license: string,
     *   maintainer: string,
     *   best_for: string,
     *   wire_protocol: string,
     *   first_released: string,
     * }|null
     */
    public static function for(string $engine): ?array
    {
        return self::all()[$engine] ?? null;
    }
}
