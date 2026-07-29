<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Jobs\EnsureSitePhpDatabaseDriverJob;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Waits for a freshly-provisioned dedicated database server to finish setup
 * (the `database`-role bootstrap installs the engine and creates the initial
 * database), then wires the site's `database` binding to it.
 *
 * Provisioning a VM + installing an engine takes many minutes, so the job is
 * its own poll loop: re-dispatch with a delay until the server reports
 * provisioning-complete, then fill the ServerDatabase host and flip the binding
 * to configured (over the shared private network where available). On a failed
 * build the binding is marked error so the resource map surfaces it.
 */
class ProvisionDedicatedDatabaseVmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** ~40 min at 30s spacing — provisioning + engine install can be slow. */
    private const MAX_ATTEMPTS = 80;

    public function __construct(
        public string $serverId,
        public string $siteId,
        public string $serverDatabaseId,
        public string $siteBindingId,
        public int $attempt = 1,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(SiteBindingManager $manager): void
    {
        $server = Server::query()->find($this->serverId);
        $site = Site::query()->find($this->siteId);
        $database = ServerDatabase::query()->find($this->serverDatabaseId);
        $binding = SiteBinding::query()->find($this->siteBindingId);

        if (! $server instanceof Server || ! $site instanceof Site
            || ! $database instanceof ServerDatabase || ! $binding instanceof SiteBinding) {
            return;
        }

        if ($binding->status === SiteBinding::STATUS_CONFIGURED) {
            return; // already wired
        }

        // The build failed — surface it on the binding and stop polling.
        if ($server->status === Server::STATUS_ERROR || $server->setup_status === Server::SETUP_STATUS_FAILED) {
            $this->fail($binding, __('The database server failed to provision.'));

            return;
        }

        if (! $server->isProvisioningComplete()) {
            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->fail($binding, __('The database server did not come online in time.'));

                return;
            }

            self::dispatch($this->serverId, $this->siteId, $this->serverDatabaseId, $this->siteBindingId, $this->attempt + 1)
                ->delay(now()->addSeconds(30));

            return;
        }

        // Ready. Record a public-IP fallback host (the binding env prefers the
        // shared private IP when the two boxes share a network — resolved inside
        // the manager), then wire the connection vars onto the binding.
        if (filled($server->ip_address) && (string) $database->host === '') {
            $database->forceFill(['host' => (string) $server->ip_address])->save();
        }

        try {
            $manager->wireServerDatabaseBinding($binding, $database->fresh() ?? $database, $site);
        } catch (\Throwable $e) {
            Log::error('database.dedicated_vm.wire_failed', [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($binding, $e->getMessage());

            return;
        }

        if (strtolower((string) $site->runtime) === 'php') {
            EnsureSitePhpDatabaseDriverJob::dispatch((string) $site->id, $database->engine);
        }
    }

    private function fail(SiteBinding $binding, string $error): void
    {
        $binding->forceFill([
            'status' => SiteBinding::STATUS_ERROR,
            'last_error' => $error,
        ])->save();
    }
}
