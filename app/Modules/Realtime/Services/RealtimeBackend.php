<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Services;

use App\Modules\Realtime\Models\RealtimeApp;
use App\Modules\Edge\Services\EdgeBackend;

/**
 * Common interface for realtime backends. The realtime layer talks to backends
 * through this and never imports the Cloudflare SDK directly — mirroring the
 * {@see EdgeBackend} pattern.
 */
interface RealtimeBackend
{
    public function providerKey(): string;

    /**
     * Publish the app's credentials so the relay accepts connections + publishes
     * for it. Idempotent: safe to call again to rotate/re-sync the record.
     */
    public function provision(RealtimeApp $app): void;

    /**
     * Remove the app from the relay (revokes connect + publish immediately).
     */
    public function deprovision(RealtimeApp $app): void;

    /**
     * Peak concurrent connections observed since the last reset, or null when
     * stats are unavailable (e.g. the app has never been connected to).
     */
    public function fetchPeakConnections(RealtimeApp $app): ?int;

    /**
     * Live stats snapshot for the app: current concurrent connections plus the
     * peak high-water mark since the last reset. Null when stats are unavailable
     * (relay unreachable, or no live relay in fake mode).
     *
     * @return array{connections: int, peakConnections: int}|null
     */
    public function fetchStats(RealtimeApp $app): ?array;

    /**
     * Reset the peak-concurrent high-water mark to the current live count.
     * Called per billing window so the next read reflects only that window.
     */
    public function resetPeakConnections(RealtimeApp $app): void;
}
