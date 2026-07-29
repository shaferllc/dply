<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Enums\ServerProvider;
use App\Models\CloudDatabase;
use App\Models\Server;
use App\Modules\Cloud\Services\VultrService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Vultr Managed Databases (DBaaS) backend — the Vultr counterpart to
 * {@see DoManagedBackend}. Co-locates a managed Postgres / MySQL / Valkey
 * cluster in a Vultr server's own region.
 *
 * ⚠️ Needs live validation: the DBaaS plan ids and per-engine default
 * versions below are taken from Vultr's published examples and can drift.
 * They're overridable per-row via meta['plan'] / the row's `version` so an
 * operator can correct them without a code change.
 */
class VultrManagedBackend implements DatabaseBackend
{
    /** dply engine → Vultr `database_engine` slug (Valkey is Redis-compatible). */
    private const ENGINE_SLUGS = [
        CloudDatabase::ENGINE_POSTGRES => 'pg',
        CloudDatabase::ENGINE_MYSQL => 'mysql',
        CloudDatabase::ENGINE_REDIS => 'valkey',
    ];

    /** Default engine version when the row doesn't pin one. */
    private const DEFAULT_VERSION = [
        'pg' => '16',
        'mysql' => '8',
        'valkey' => '7',
    ];

    /**
     * Portable size tier → Vultr DBaaS plan id. `large` falls back to the
     * startup plan until a validated higher tier is wired in.
     */
    private const PLAN_TIERS = [
        'small' => 'vultr-dbaas-hobbyist-cc-1-25-1',
        'medium' => 'vultr-dbaas-startup-cc-1-55-2',
        'large' => 'vultr-dbaas-startup-cc-1-55-2',
    ];

    /** Approximate single-node monthly USD per tier (display only). */
    private const MONTHLY_COST = [
        'small' => 15,
        'medium' => 30,
        'large' => 30,
    ];

    public function key(): string
    {
        return CloudDatabase::BACKEND_VULTR;
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
        if ($server->provider !== ServerProvider::Vultr) {
            return null;
        }

        $region = strtolower(trim((string) $server->region));

        return $region !== '' ? $region : null;
    }

    public function estimatedMonthlyCost(string $size): ?int
    {
        return self::MONTHLY_COST[$size] ?? null;
    }

    public function provision(CloudDatabase $database): void
    {
        $engineSlug = self::ENGINE_SLUGS[$database->engine] ?? 'pg';
        $version = $database->version !== ''
            ? $database->version
            : (self::DEFAULT_VERSION[$engineSlug] ?? '16');

        $cluster = $this->service($database)->createDatabaseCluster(
            $engineSlug,
            $version,
            $this->planFor($database),
            $database->region !== '' ? $database->region : 'ewr',
            $this->clusterLabel($database),
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

        // Normalize Vultr's `running` to the shared `online` ready-state so the
        // provisioning job's status check stays backend-agnostic.
        $status = (string) ($cluster['status'] ?? '');

        return [
            'status' => $status === 'running' ? 'online' : $status,
            'connection' => $connection,
        ];
    }

    public function lockNetworkTo(CloudDatabase $database, Server $server): void
    {
        if (! is_string($database->backend_id) || $database->backend_id === '') {
            return;
        }

        $ips = array_values(array_filter([
            filled($server->ip_address) ? (string) $server->ip_address : null,
            filled($server->private_ip_address) ? (string) $server->private_ip_address : null,
        ]));
        if ($ips === []) {
            return;
        }

        try {
            $this->service($database)->setDatabaseTrustedSources((string) $database->backend_id, $ips);
        } catch (\Throwable $e) {
            // Best-effort: a lockdown failure must not strand an online database.
            Log::warning('database.vultr_managed.lockdown_failed', [
                'cloud_database_id' => $database->id,
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function planFor(CloudDatabase $database): string
    {
        $override = (string) ($database->meta['plan'] ?? '');
        if ($override !== '') {
            return $override;
        }

        return self::PLAN_TIERS[$database->size] ?? self::PLAN_TIERS['small'];
    }

    private function service(CloudDatabase $database): VultrService
    {
        $database->loadMissing('providerCredential');
        $credential = $database->providerCredential;
        if ($credential === null) {
            throw new RuntimeException('The database has no Vultr credential.');
        }

        return new VultrService($credential);
    }

    private function clusterLabel(CloudDatabase $database): string
    {
        $slug = Str::slug($database->name) ?: 'db';

        return 'dply-'.$slug.'-'.Str::lower(Str::random(6));
    }
}
