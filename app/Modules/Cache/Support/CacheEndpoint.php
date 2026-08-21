<?php

declare(strict_types=1);

namespace App\Modules\Cache\Support;

/**
 * The base URL customers point DYNAMODB_ENDPOINT at.
 *
 * Falls back to DPLY_PUBLIC_APP_URL rather than APP_URL for the same reason the
 * queue's endpoint does: APP_URL is typically a local *.test in development, and
 * a customer's function on DigitalOcean could never resolve it. An unreachable
 * endpoint that looks configured is worse than none.
 */
final class CacheEndpoint
{
    public static function base(): string
    {
        $configured = trim((string) config('cache_service.public_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $public = trim((string) env('DPLY_PUBLIC_APP_URL', ''));

        return $public === '' ? '' : rtrim($public, '/').'/api/cache/v1';
    }
}
