<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Jobs\EnsureSitePhpDatabaseDriverJob;
use App\Jobs\RefreshServerInventoryJob;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Services\Servers\DockerDatabaseProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Waits for a freshly-provisioned Docker host, starts the database container,
 * then wires the site's database binding over the shared private network.
 */
class ProvisionDedicatedDockerDatabaseVmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

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

    public function handle(
        DockerDatabaseProvisioner $provisioner,
        SiteBindingManager $manager,
    ): void {
        $server = Server::query()->find($this->serverId);
        $site = Site::query()->find($this->siteId);
        $database = ServerDatabase::query()->find($this->serverDatabaseId);
        $binding = SiteBinding::query()->find($this->siteBindingId);

        if (! $server instanceof Server || ! $site instanceof Site
            || ! $database instanceof ServerDatabase || ! $binding instanceof SiteBinding) {
            return;
        }

        if ($binding->status === SiteBinding::STATUS_CONFIGURED) {
            return;
        }

        if ($server->status === Server::STATUS_ERROR || $server->setup_status === Server::SETUP_STATUS_FAILED) {
            $this->fail($binding, __('The Docker database server failed to provision.'));

            return;
        }

        if (! $server->isProvisioningComplete()) {
            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->fail($binding, __('The Docker database server did not come online in time.'));

                return;
            }

            self::dispatch($this->serverId, $this->siteId, $this->serverDatabaseId, $this->siteBindingId, $this->attempt + 1)
                ->delay(now()->addSeconds(30));

            return;
        }

        if (! $server->dockerEnginePresent()) {
            RefreshServerInventoryJob::dispatchSync((string) $server->id);
            $server = $server->fresh() ?? $server;
        }

        if (! $server->dockerEnginePresent()) {
            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->fail($binding, __('Docker did not become available on the database server in time.'));

                return;
            }

            self::dispatch($this->serverId, $this->siteId, $this->serverDatabaseId, $this->siteBindingId, $this->attempt + 1)
                ->delay(now()->addSeconds(30));

            return;
        }

        if (filled($server->ip_address) && (string) $database->host === '') {
            $database->forceFill(['host' => (string) $server->ip_address])->save();
        }

        try {
            $result = $provisioner->provision(
                $server,
                $site,
                $database->fresh() ?? $database,
                remoteAccess: true,
                allowedFrom: (string) $database->allowed_from,
            );

            $config = is_array($binding->config) ? $binding->config : [];
            $config['docker_container'] = $result['container'];
            $config['docker_volume'] = 'dply-db-'.$result['container'];
            $config['host_port'] = $result['host_port'];
            $binding->forceFill(['config' => $config])->save();

            $manager->wireServerDatabaseBinding($binding->fresh() ?? $binding, $database->fresh() ?? $database, $site);
        } catch (\Throwable $e) {
            Log::error('database.docker_vm.wire_failed', [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($binding, $e->getMessage());

            return;
        }

        if (strtolower((string) $site->runtime) === 'php' && $database->engine !== 'redis') {
            EnsureSitePhpDatabaseDriverJob::dispatch((string) $site->id, (string) $database->engine);
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
