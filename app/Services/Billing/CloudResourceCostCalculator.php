<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\CloudBucket;
use App\Models\CloudDatabase;
use App\Models\CloudWorker;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Computes the monthly **metered** cost of the DigitalOcean resources a set of
 * dply Cloud apps run on, with dply's markup applied.
 *
 * Cloud apps are not a flat unit: each one provisions an App Platform container
 * (× instance count), optional background-worker containers, one or more
 * managed databases, and object-storage buckets — all on dply-owned infra that
 * dply pays DO for. The flat `cloud_cents` platform fee covers orchestration;
 * this calculator covers the pass-through-plus-margin infrastructure cost so a
 * Cloud app with a database is never billed below what it costs to run.
 *
 * Returns cents. Billed via a metered Stripe line (quantity = cents), mirroring
 * the Edge delivery-usage line.
 */
class CloudResourceCostCalculator
{
    /**
     * Total marked-up monthly cost, in cents, of the provider resources backing
     * the given billable Cloud sites. Shared databases/buckets are billed once
     * regardless of how many sites they attach to.
     *
     * @param  Collection<int, Site>  $cloudSites  Billable dply Cloud apps (container_active, non-preview).
     */
    public function subtotalCents(Collection $cloudSites): int
    {
        if ($cloudSites->isEmpty()) {
            return 0;
        }

        $markupPercent = max(0, (int) config('subscription.standard.cloud_markup_percent', 0));
        $containerRates = (array) config('subscription.standard.cloud_container_cents', []);
        $databaseRates = (array) config('subscription.standard.cloud_database_cents', []);
        $bucketRaw = (int) config('subscription.standard.cloud_bucket_cents', 0);

        $siteIds = $cloudSites->pluck('id')->all();

        $total = 0;

        // Web containers — size tier × instance count, per app.
        foreach ($cloudSites as $site) {
            $meta = ($site->meta );
            $tier = (string) ($meta['container']['size_tier'] ?? 'small');
            $instances = max(1, (int) ($meta['container']['instance_count'] ?? 1));
            $total += $this->withMarkup($this->rate($containerRates, $tier), $markupPercent) * $instances;
        }

        // Background workers — each is its own container component on DO.
        if (Schema::hasTable('cloud_workers')) {
            CloudWorker::query()
                ->whereIn('site_id', $siteIds)
                ->whereIn('status', [CloudWorker::STATUS_ACTIVE, CloudWorker::STATUS_PROVISIONING])
                ->get(['id', 'site_id', 'type', 'size', 'instance_count', 'status'])
                ->each(function (CloudWorker $worker) use (&$total, $containerRates, $markupPercent): void {
                    $perInstance = $this->withMarkup($this->rate($containerRates, (string) $worker->size), $markupPercent);
                    $total += $perInstance * max(1, $worker->effectiveInstanceCount());
                });
        }

        // Managed databases — billed once each, even when shared across apps.
        if (Schema::hasTable('cloud_databases')) {
            CloudDatabase::query()
                ->whereIn('status', [CloudDatabase::STATUS_ACTIVE, CloudDatabase::STATUS_PROVISIONING])
                ->whereHas('sites', fn ($q) => $q->whereIn('sites.id', $siteIds))
                ->get(['id', 'size', 'status'])
                ->each(function (CloudDatabase $database) use (&$total, $databaseRates, $markupPercent): void {
                    $total += $this->withMarkup($this->rate($databaseRates, (string) $database->size), $markupPercent);
                });
        }

        // Object-storage buckets — only once provisioned (a pending bucket has
        // no real provider subscription yet, so it accrues nothing).
        if ($bucketRaw > 0 && Schema::hasTable('cloud_buckets')) {
            $bucketCount = CloudBucket::query()
                ->where('status', CloudBucket::STATUS_ACTIVE)
                ->whereHas('sites', fn ($q) => $q->whereIn('sites.id', $siteIds))
                ->count();
            $total += $this->withMarkup($bucketRaw, $markupPercent) * $bucketCount;
        }

        return $total;
    }

    /**
     * Look up a raw provider rate for a size tier, falling back to the cheapest
     * known tier so an unrecognized slug never bills $0.
     *
     * @param  array<string, mixed> $rates
     */
    private function rate(array $rates, string $tier): int
    {
        if (isset($rates[$tier])) {
            return (int) $rates[$tier];
        }

        if (isset($rates['small'])) {
            return (int) $rates['small'];
        }

        return (int) (reset($rates) ?: 0);
    }

    private function withMarkup(int $rawCents, int $markupPercent): int
    {
        return (int) round($rawCents * (100 + $markupPercent) / 100);
    }
}
