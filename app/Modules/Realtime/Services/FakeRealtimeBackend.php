<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Services;

use App\Modules\Realtime\Models\RealtimeApp;
use Illuminate\Support\Facades\Cache;

/**
 * Local/test backend — records app credentials in the cache instead of calling
 * Cloudflare. Credentials and the connection snippet still work end-to-end; the
 * live relay is only present if you run `wrangler dev` in packages/realtime-worker
 * and point DPLY_REALTIME_HOST at it.
 */
class FakeRealtimeBackend implements RealtimeBackend
{
    private const STORE_KEY = 'realtime:fake:apps';

    public function providerKey(): string
    {
        return 'dply_realtime';
    }

    public function provision(RealtimeApp $app): void
    {
        $store = $this->store();
        $store[$app->kvKeyById()] = $app->kvRecord();
        $store[$app->kvKeyByKey()] = $app->kvRecord();
        Cache::forever(self::STORE_KEY, $store);
    }

    public function deprovision(RealtimeApp $app): void
    {
        $store = $this->store();
        unset($store[$app->kvKeyById()], $store[$app->kvKeyByKey()]);
        Cache::forever(self::STORE_KEY, $store);
    }

    public function fetchPeakConnections(RealtimeApp $app): ?int
    {
        // No live relay in fake mode — report nothing rather than a fake number.
        return null;
    }

    public function fetchStats(RealtimeApp $app): ?array
    {
        // No live relay in fake mode — report nothing rather than fake numbers.
        return null;
    }

    public function resetPeakConnections(RealtimeApp $app): void
    {
        // No-op: nothing to reset without a live relay.
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function store(): array
    {
        $value = Cache::get(self::STORE_KEY, []);

        return is_array($value) ? $value : [];
    }
}
