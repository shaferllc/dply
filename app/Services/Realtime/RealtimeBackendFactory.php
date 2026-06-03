<?php

declare(strict_types=1);

namespace App\Services\Realtime;

/**
 * Resolves the active realtime backend. Returns the local cache-backed fake
 * when fake mode is enabled in an allowed environment, otherwise the
 * Cloudflare-backed backend. Mirrors how the Edge layer selects its backend.
 */
class RealtimeBackendFactory
{
    public static function make(): RealtimeBackend
    {
        if (self::fakeEnabled()) {
            return new FakeRealtimeBackend;
        }

        return CloudflareRealtimeBackend::fromConfig();
    }

    public static function fakeEnabled(): bool
    {
        if (! (bool) config('realtime.fake.enabled')) {
            return false;
        }

        $allowed = (array) config('realtime.fake.allowed_environments', []);

        return in_array(app()->environment(), $allowed, true);
    }
}
