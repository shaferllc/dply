<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Services;

/**
 * Resolves the active realtime backend. Returns the local cache-backed fake
 * when fake mode is enabled in an allowed environment, otherwise the
 * Cloudflare-backed backend. Mirrors how the Edge layer selects its backend.
 */
class RealtimeBackendFactory
{
    /**
     * An explicitly bound backend wins over both branches below, so a caller
     * that needs to substitute one — a test asserting on the order of relay
     * calls, say — can bind it rather than reach through these statics.
     */
    public static function make(): RealtimeBackend
    {
        if (app()->bound(RealtimeBackend::class)) {
            return app(RealtimeBackend::class);
        }

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
