<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Enums\ServerProvider;
use App\Models\CloudDatabase;
use App\Models\Server;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Support\Servers\ProviderManagedDatabaseRegion;
use App\Support\Servers\ProviderResourceTags;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DigitalOcean Managed Databases backend.
 *
 * Wraps the existing {@see DigitalOceanService} Managed Databases endpoints
 * (the same ones the Cloud-site flow uses) so a VM site can co-locate a
 * managed Postgres / MySQL / Valkey cluster in its own DigitalOcean region.
 * Adds the network lockdown step (trusted sources) that the seamless
 * "just works, but not publicly exposed" placement requires.
 */
class DoManagedBackend implements DatabaseBackend
{
    /** Portable size tier → approximate single-node monthly USD (display only). */
    private const MONTHLY_COST = [
        'small' => 15,
        'medium' => 30,
        'large' => 60,
    ];

    public function key(): string
    {
        return CloudDatabase::BACKEND_DIGITALOCEAN;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, [
            self::CAP_USERS,
            self::CAP_RESIZE,
            self::CAP_METRICS,
            self::CAP_BACKUPS,
        ], true);
    }

    public function supportedEngines(): array
    {
        return [
            CloudDatabase::ENGINE_POSTGRES,
            CloudDatabase::ENGINE_MYSQL,
            CloudDatabase::ENGINE_REDIS,
        ];
    }

    public function regionForServer(Server $server): ?string
    {
        if ($server->provider !== ServerProvider::DigitalOcean) {
            return null;
        }

        $region = ProviderManagedDatabaseRegion::normalize('digitalocean', (string) $server->region);

        return $region !== '' ? $region : null;
    }

    public function estimatedMonthlyCost(string $size): ?int
    {
        return self::MONTHLY_COST[$size] ?? null;
    }

    public function provision(CloudDatabase $database): void
    {
        $service = $this->service($database);

        $cluster = $service->createDatabaseCluster(
            $database->backendEngineSlug(),
            $database->region !== '' ? $database->region : 'nyc3',
            $database->backendSizeSlug(),
            $this->clusterName($database),
            $database->backendEngineVersion(),
            ProviderResourceTags::forCloudDatabase($database),
        );

        $database->forceFill(['backend_id' => (string) $cluster['id']])->save();
    }

    public function poll(CloudDatabase $database): array
    {
        $service = $this->service($database);

        if (! is_string($database->backend_id) || $database->backend_id === '') {
            $this->provision($database);
        }

        $cluster = $service->getDatabaseCluster((string) $database->backend_id);
        $this->ensureProviderTags($service, $database, $cluster);
        $connection = $cluster['connection'];

        return [
            'status' => (string) $cluster['status'],
            'connection' => $connection,
        ];
    }

    public function lockNetworkTo(CloudDatabase $database, Server $server): void
    {
        if (! is_string($database->backend_id) || $database->backend_id === '') {
            return;
        }

        // Add the app server as the cluster's only trusted source so the DB is
        // reachable by the app but closed to the public internet. Prefer the
        // DO droplet id (survives IP churn); fall back to the public IP.
        $rules = [];
        if (filled($server->provider_id) && $server->provider === ServerProvider::DigitalOcean) {
            $rules[] = ['type' => 'droplet', 'value' => (string) $server->provider_id];
        } elseif (filled($server->ip_address)) {
            $rules[] = ['type' => 'ip_addr', 'value' => (string) $server->ip_address];
        }

        $service = $this->service($database);
        $this->ensureProviderTags($service, $database, []);

        if ($rules === []) {
            return;
        }

        try {
            $service->setDatabaseTrustedSources((string) $database->backend_id, $rules);
        } catch (\Throwable $e) {
            // Lockdown is best-effort: a failure here must not strand an
            // otherwise-online database. Log and leave it on the provider
            // default (public + SSL) so the connection still works.
            Log::warning('database.do_managed.lockdown_failed', [
                'cloud_database_id' => $database->id,
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resize(CloudDatabase $database, string $size): void
    {
        if (! is_string($database->backend_id) || $database->backend_id === '') {
            throw new RuntimeException(__('This cluster has no DigitalOcean id yet.'));
        }

        $size = CloudDatabase::resolveSizeSlug($size);
        $service = $this->service($database);

        try {
            $available = $service->getDatabaseEngineSizes($database->backendEngineSlug());
            if ($available !== [] && ! in_array($size, $available, true)) {
                throw new RuntimeException(__('DigitalOcean does not offer that plan for this cluster.'));
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            // Catalog is best-effort — still send the requested slug.
        }

        $service->resizeDatabaseCluster((string) $database->backend_id, $size);
    }

    public function metricCatalog(CloudDatabase $database): array
    {
        $catalog = [
            ['key' => 'cpu', 'label' => __('CPU'), 'format' => 'percent'],
            ['key' => 'memory_utilization', 'label' => __('Memory'), 'format' => 'percent'],
            ['key' => 'load_1', 'label' => __('Load (1m)'), 'format' => 'load'],
        ];

        // Valkey holds its dataset in memory; DigitalOcean reports no disk
        // series for it, and an always-empty chart reads as a broken one.
        if ($database->engine !== CloudDatabase::ENGINE_REDIS) {
            array_splice($catalog, 2, 0, [
                ['key' => 'disk_utilization', 'label' => __('Disk'), 'format' => 'percent'],
            ]);
        }

        return $catalog;
    }

    public function metric(CloudDatabase $database, string $metric, int $start, int $end): array
    {
        if (! is_string($database->backend_id) || $database->backend_id === '') {
            return [];
        }

        return $this->service($database)->getDatabaseMetric($database->backend_id, $metric, $start, $end);
    }

    public function backups(CloudDatabase $database): array
    {
        if (! is_string($database->backend_id) || $database->backend_id === '') {
            return [];
        }

        return $this->service($database)->listDatabaseBackups($database->backend_id);
    }

    public function provisionFromBackup(CloudDatabase $target, CloudDatabase $source, string $backupCreatedAt): void
    {
        if (! is_string($source->backend_id) || $source->backend_id === '') {
            throw new RuntimeException(__('The source cluster has no DigitalOcean id.'));
        }

        $service = $this->service($target);

        // `backup_restore` keys off the provider's cluster *name*, which dply
        // generates at create and never stores — so it has to be read back.
        // Null-coalesced before trim(): DigitalOcean can return a cluster
        // without a `name`, and trim(null) is a TypeError on PHP 8 — which
        // would throw past the empty-name guard immediately below instead of
        // reporting it. Recovered from stash/0-queue-fleet-panel-wip.
        $sourceName = trim((string) ($service->getDatabaseCluster($source->backend_id)['name'] ?? ''));
        if ($sourceName === '') {
            throw new RuntimeException(__('DigitalOcean did not report a name for the source cluster.'));
        }

        $cluster = $service->createDatabaseClusterFromBackup(
            $target->backendEngineSlug(),
            $target->region !== '' ? $target->region : 'nyc3',
            $target->backendSizeSlug(),
            $this->clusterName($target),
            $sourceName,
            $backupCreatedAt,
            $target->backendEngineVersion(),
            ProviderResourceTags::forCloudDatabase($target),
        );

        $target->forceFill(['backend_id' => (string) $cluster['id']])->save();
    }

    /**
     * @param  array{tags?: list<string>}  $cluster
     */
    private function ensureProviderTags(DigitalOceanService $service, CloudDatabase $database, array $cluster): void
    {
        try {
            $service->ensureDatabaseClusterTags(
                (string) $database->backend_id,
                ProviderResourceTags::forCloudDatabase($database),
                $cluster['tags'] ?? [],
            );
        } catch (\Throwable $e) {
            Log::warning('database.do_managed.tags_failed', [
                'cloud_database_id' => $database->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function service(CloudDatabase $database): DigitalOceanService
    {
        $database->loadMissing('providerCredential');
        $credential = $database->providerCredential;
        if ($credential === null) {
            throw new RuntimeException('The database has no DigitalOcean credential.');
        }

        return new DigitalOceanService($credential);
    }

    private function clusterName(CloudDatabase $database): string
    {
        $slug = Str::slug($database->name) ?: 'db';

        return 'dply-'.$slug.'-'.Str::lower(Str::random(6));
    }
}
