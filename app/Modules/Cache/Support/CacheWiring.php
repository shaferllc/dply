<?php

declare(strict_types=1);

namespace App\Modules\Cache\Support;

use App\Models\ServiceCredential;
use App\Modules\Cache\Models\ManagedCache;

/**
 * The env a customer's app needs to talk to a cache.
 *
 * Six keys, all machine-written, all read by Laravel's STOCK `dynamodb` store —
 * there is no package to install. That is the whole point of impersonating an
 * AWS API rather than inventing a protocol (docs/adr/dply-cache.md, decision 2).
 *
 * `DYNAMODB_CACHE_TABLE` is the cache's opaque id rather than its name: the
 * value is injected on attach and never typed by a human, so readability buys
 * nothing, while a guessable identifier in an authorization path is a bad trade
 * (decision 14). The grant map is the authority regardless of what a client
 * sends here — this is a convenience, not a boundary.
 *
 * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY are deliberately shared with dply
 * Queue's `sqs` store, because Laravel's two config files read the same two env
 * vars and there is no way for one app to hold two pairs (decision 6).
 */
final class CacheWiring
{
    /**
     * Every key attach writes, so detach can strip exactly these.
     *
     * The AWS pair is included: leaving credentials behind after a detach means
     * an app that still tries, and fails, on every cache call.
     *
     * @var list<string>
     */
    public const MANAGED_KEYS = [
        'CACHE_STORE',
        'CACHE_DRIVER',
        'CACHE_PREFIX',
        'DYNAMODB_ENDPOINT',
        'DYNAMODB_CACHE_TABLE',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_DEFAULT_REGION',
    ];

    /**
     * @return array<string, string>
     */
    public static function envFor(
        ManagedCache $cache,
        ServiceCredential $credential,
        string $plaintextSecret,
        ?string $keyPrefix = null,
    ): array {
        $env = [
            'CACHE_STORE' => 'dynamodb',
            // The pre-11 alias, so an older app picks the store up too. Same
            // reason ManagesCacheBindings writes both.
            'CACHE_DRIVER' => 'dynamodb',
            'DYNAMODB_ENDPOINT' => CacheEndpoint::base(),
            'DYNAMODB_CACHE_TABLE' => $cache->id,
            'AWS_ACCESS_KEY_ID' => $credential->accessKeyId(),
            'AWS_SECRET_ACCESS_KEY' => $plaintextSecret,
            'AWS_DEFAULT_REGION' => (string) config('cache_service.region', 'us-east-1'),
        ];

        if ($keyPrefix !== null && trim($keyPrefix) !== '') {
            $env['CACHE_PREFIX'] = trim($keyPrefix);
        }

        return $env;
    }

    /**
     * The same map rendered as a `.env` block, for the wiring panel.
     *
     * The secret is only ever present here at attach time; the panel shows a
     * placeholder afterwards, because dply cannot re-reveal what it hashed.
     *
     * @param  array<string, string>  $env
     */
    public static function asEnvBlock(array $env): string
    {
        $lines = [];

        foreach ($env as $key => $value) {
            $lines[] = $key.'='.(preg_match('/\s/', $value) === 1 ? '"'.$value.'"' : $value);
        }

        return implode("\n", $lines);
    }
}
