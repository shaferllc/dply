<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Models\CloudDatabase;
use App\Models\CloudDatabaseTrustedSource;
use App\Models\User;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Cloud\Services\VultrService;
use App\Support\Servers\DatabaseConnectionTarget;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Grants and revokes temporary operator access to a managed database cluster.
 *
 * Two provider facts drive the whole design:
 *
 * 1. Both DigitalOcean and Vultr *replace* the entire allowlist on write, and
 *    neither exposes an add-one call. Every mutation here is therefore
 *    read → union → write, and the rules dply did not create are carried
 *    through untouched. Dropping the app-server rule would sever a live site
 *    from its own database.
 * 2. Neither provider records who added a rule or when. dply keeps its own
 *    ledger ({@see CloudDatabaseTrustedSource}) so expiry can remove exactly
 *    what dply added and nothing else — a customer's hand-added office IP must
 *    survive the reaper.
 */
class TrustedSourceManager
{
    public function writesEnabled(): bool
    {
        return (bool) config('server_database.trusted_source_writes', true);
    }

    public function supports(CloudDatabase $database): bool
    {
        return $this->writesEnabled()
            && DatabaseConnectionTarget::backendSupportsTrustedSourceWrites((string) $database->backend)
            && filled($database->backend_id);
    }

    public function defaultExpiry(): CarbonInterface
    {
        return $this->clampExpiry(now()->addHours($this->ttlHours()));
    }

    public function ttlHours(): int
    {
        return max(1, (int) config('server_database.trusted_source_ttl_hours', 8));
    }

    public function maxTtlHours(): int
    {
        return max(1, (int) config('server_database.trusted_source_max_ttl_hours', 24));
    }

    /**
     * Temporary access that outlives the session it was granted for is just an
     * open firewall with extra steps. A misconfigured TTL, or a caller passing
     * its own expiry, must not be able to leave a cluster exposed for months —
     * so every expiry is capped here rather than trusted.
     */
    public function clampExpiry(CarbonInterface $expiresAt): CarbonInterface
    {
        $ceiling = now()->addHours($this->maxTtlHours());

        return $expiresAt->greaterThan($ceiling) ? $ceiling : $expiresAt;
    }

    /**
     * dply-tracked allowances still believed to be on the provider's list.
     *
     * @return Collection<int, CloudDatabaseTrustedSource>
     */
    public function liveFor(CloudDatabase $database): Collection
    {
        return CloudDatabaseTrustedSource::query()
            ->where('cloud_database_id', $database->id)
            ->live()
            ->with('createdBy')
            ->orderBy('expires_at')
            ->get();
    }

    public function liveForUser(CloudDatabase $database, User $user): ?CloudDatabaseTrustedSource
    {
        return CloudDatabaseTrustedSource::query()
            ->where('cloud_database_id', $database->id)
            ->where('created_by_user_id', $user->id)
            ->live()
            ->latest('expires_at')
            ->first();
    }

    /**
     * Add an operator IP, preserving every existing rule.
     */
    public function allow(
        CloudDatabase $database,
        string $ip,
        User $actor,
        ?CarbonInterface $expiresAt = null,
    ): CloudDatabaseTrustedSource {
        if (! $this->supports($database)) {
            throw new RuntimeException('This database does not support trusted-source changes.');
        }

        // Private/reserved addresses are rejected outright: a 10.0.0.0/8 entry on
        // a managed cluster's allowlist grants nothing and misleads whoever reads
        // it later.
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('A valid public IP address is required.');
        }

        $expiresAt = $this->clampExpiry($expiresAt ?? $this->defaultExpiry());

        // Ledger first: if the provider write throws, we still know an attempt
        // was made and the reaper can clean up a partially applied rule.
        $record = CloudDatabaseTrustedSource::query()->create([
            'cloud_database_id' => $database->id,
            'ip_address' => $ip,
            'created_by_user_id' => $actor->id,
            'expires_at' => $expiresAt,
        ]);

        $this->syncProvider($database);

        $this->log($database, $actor, 'trusted_source_added', [
            'ip_address' => $ip,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return $record;
    }

    public function revoke(CloudDatabaseTrustedSource $record, ?User $actor = null): void
    {
        $database = $record->cloudDatabase;
        if (! $database instanceof CloudDatabase) {
            $record->forceFill(['revoked_at' => now()])->save();

            return;
        }

        $record->forceFill(['revoked_at' => now()])->save();
        $this->syncProvider($database);

        $this->log($database, $actor, 'trusted_source_revoked', [
            'ip_address' => $record->ip_address,
        ]);
    }

    /**
     * Strip every expired allowance. Returns the number of clusters resynced.
     */
    public function reapExpired(): int
    {
        $expired = CloudDatabaseTrustedSource::query()
            ->reapable()
            ->with('cloudDatabase')
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $now = now();
        $touched = 0;

        foreach ($expired->groupBy('cloud_database_id') as $records) {
            /** @var Collection<int, CloudDatabaseTrustedSource> $records */
            $database = $records->first()?->cloudDatabase;

            foreach ($records as $record) {
                $record->forceFill(['revoked_at' => $now])->save();
            }

            if (! $database instanceof CloudDatabase || ! $this->supports($database)) {
                continue;
            }

            $this->syncProvider($database);
            $touched++;

            $this->log($database, null, 'trusted_source_expired', [
                'ip_addresses' => $records->pluck('ip_address')->all(),
            ]);
        }

        return $touched;
    }

    /**
     * Push the union of {provider rules dply did not create} + {live dply
     * allowances} back to the provider.
     *
     * The subtraction is deliberately limited to addresses in dply's ledger for
     * this cluster: anything else on the list — the app-server rule, an address
     * added in the provider console — is foreign and is written back unchanged.
     */
    private function syncProvider(CloudDatabase $database): void
    {
        if (! $this->supports($database)) {
            return;
        }

        $clusterId = (string) $database->backend_id;
        $live = $this->liveFor($database)->pluck('ip_address')->map(strval(...))->unique();

        // Every address dply has ever tracked here, so a revoked/expired one can
        // be recognised as ours and dropped — while foreign rules are kept.
        $managed = CloudDatabaseTrustedSource::query()
            ->where('cloud_database_id', $database->id)
            ->pluck('ip_address')
            ->map(strval(...))
            ->unique();

        match ((string) $database->backend) {
            CloudDatabase::BACKEND_DIGITALOCEAN => $this->syncDigitalOcean($database, $clusterId, $live, $managed),
            CloudDatabase::BACKEND_VULTR => $this->syncVultr($database, $clusterId, $live, $managed),
            default => null,
        };
    }

    /**
     * @param  Collection<int, string>  $live
     * @param  Collection<int, string>  $managed
     */
    private function syncDigitalOcean(CloudDatabase $database, string $clusterId, Collection $live, Collection $managed): void
    {
        $service = $this->digitalOcean($database);

        $existing = $service->getDatabaseTrustedSources($clusterId);

        $rules = [];
        foreach ($existing as $rule) {
            // Keep droplet/tag/k8s rules always, and any ip_addr dply never added.
            if ($rule['type'] !== 'ip_addr' || ! $managed->contains($rule['value'])) {
                $rules[] = $rule;
            }
        }

        foreach ($live as $ip) {
            $rules[] = ['type' => 'ip_addr', 'value' => $ip];
        }

        $service->setDatabaseTrustedSources($clusterId, $rules);
    }

    /**
     * @param  Collection<int, string>  $live
     * @param  Collection<int, string>  $managed
     */
    private function syncVultr(CloudDatabase $database, string $clusterId, Collection $live, Collection $managed): void
    {
        $service = $this->vultr($database);

        $existing = collect($service->getDatabaseTrustedSources($clusterId))
            ->reject(fn (string $ip): bool => $managed->contains($ip));

        $service->setDatabaseTrustedSources(
            $clusterId,
            $existing->merge($live)->unique()->values()->all(),
        );
    }

    private function digitalOcean(CloudDatabase $database): DigitalOceanService
    {
        $database->loadMissing('providerCredential');
        $credential = $database->providerCredential;
        if ($credential === null) {
            throw new RuntimeException('The database has no DigitalOcean credential.');
        }

        return new DigitalOceanService($credential);
    }

    private function vultr(CloudDatabase $database): VultrService
    {
        $database->loadMissing('providerCredential');
        $credential = $database->providerCredential;
        if ($credential === null) {
            throw new RuntimeException('The database has no Vultr credential.');
        }

        return new VultrService($credential);
    }

    /**
     * Log straight to the organisation audit feed.
     *
     * Deliberately not ServerDatabaseAuditLogger: that one is keyed on a Server,
     * and a managed cluster attached through a SiteBinding may have no row in the
     * cloud_database_site pivot at all — so a server-keyed logger would silently
     * drop exactly the events that matter most here. CloudDatabase carries its
     * organization directly and makes a perfectly good audit subject.
     *
     * @param  array<string, mixed>  $meta
     */
    private function log(CloudDatabase $database, ?User $actor, string $event, array $meta): void
    {
        $database->loadMissing('organization');
        $organization = $database->organization;
        if ($organization === null) {
            return;
        }

        audit_log(
            $organization,
            $actor,
            'cloud.databases.'.$event,
            $database,
            null,
            array_merge($meta, ['cloud_database_id' => (string) $database->id]),
        );
    }
}
