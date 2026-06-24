<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Enums\ServerProvider;
use App\Models\CloudDatabase;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DigitalOcean Managed Databases backend.
 *
 * Wraps the existing {@see DigitalOceanService} Managed Databases endpoints
 * (the same ones the Cloud-site flow uses) so a VM site can co-locate a
 * managed Postgres / MySQL / Redis cluster in its own DigitalOcean region.
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

        $region = $this->normalizeRegion((string) $server->region);

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
        $connection = is_array($cluster['connection'] ?? null) ? $cluster['connection'] : [];

        return [
            'status' => (string) ($cluster['status'] ?? ''),
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

        if ($rules === []) {
            return;
        }

        try {
            $this->service($database)->setDatabaseTrustedSources((string) $database->backend_id, $rules);
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

    /**
     * Droplet regions (e.g. `nyc3`, `ams3`) are already valid Managed Database
     * slugs; older short codes (`nyc`, `ams`) are mapped to the numbered slug
     * DO's database API accepts. Mirrors CreateCloudDatabase's normalization.
     */
    private function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));

        if (preg_match('/^[a-z]{3}[0-9]$/', $region) === 1) {
            return $region;
        }

        return match ($region) {
            'ams' => 'ams3',
            'nyc' => 'nyc3',
            'fra' => 'fra1',
            'sfo' => 'sfo3',
            'sgp' => 'sgp1',
            'lon' => 'lon1',
            'tor' => 'tor1',
            'blr' => 'blr1',
            'syd' => 'syd1',
            default => $region,
        };
    }
}
