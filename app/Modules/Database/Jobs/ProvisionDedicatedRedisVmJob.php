<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Support\Sites\FailedDedicatedVmBinding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Waits for a freshly-provisioned dedicated Redis server to finish the
 * `redis_server` recipe, then wires the site's `redis` binding to it.
 */
class ProvisionDedicatedRedisVmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** ~40 min at 30s spacing — provisioning + Redis install can be slow. */
    private const MAX_ATTEMPTS = 80;

    public function __construct(
        public string $serverId,
        public string $siteId,
        public string $serverCacheServiceId,
        public string $siteBindingId,
        public int $attempt = 1,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(SiteBindingManager $manager): void
    {
        $server = Server::query()->find($this->serverId);
        $site = Site::query()->find($this->siteId);
        $service = ServerCacheService::query()->find($this->serverCacheServiceId);
        $binding = SiteBinding::query()->find($this->siteBindingId);

        if (! $server instanceof Server || ! $site instanceof Site
            || ! $service instanceof ServerCacheService || ! $binding instanceof SiteBinding) {
            return;
        }

        if ($binding->status === SiteBinding::STATUS_CONFIGURED) {
            return;
        }

        if ($server->status === Server::STATUS_ERROR || $server->setup_status === Server::SETUP_STATUS_FAILED) {
            $this->fail($binding, __('The Redis server failed to provision.'), $server);

            return;
        }

        if (! $server->isProvisioningComplete()) {
            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->fail($binding, __('The Redis server did not come online in time.'), $server);

                return;
            }

            self::dispatch($this->serverId, $this->siteId, $this->serverCacheServiceId, $this->siteBindingId, $this->attempt + 1)
                ->delay(now()->addSeconds(30));

            return;
        }

        $service->forceFill([
            'status' => ServerCacheService::STATUS_RUNNING,
        ])->save();

        try {
            $manager->wireServerCacheBinding($binding, $service->fresh() ?? $service, $site);
        } catch (\Throwable $e) {
            Log::error('redis.dedicated_vm.wire_failed', [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($binding, $e->getMessage(), $server);
        }
    }

    private function fail(SiteBinding $binding, string $error, ?Server $server = null): void
    {
        app(FailedDedicatedVmBinding::class)->settle($binding, $error, $server);
    }
}
