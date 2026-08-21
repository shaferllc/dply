<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use Throwable;

/**
 * Reclaims superseded asset objects and measures what each site is storing.
 *
 * One job, two outputs, because they need the same work: listing a prefix
 * returns every object's size AND mtime in a single call, so the sweep that
 * decides what to delete produces the exact storage number for billing on the
 * way past. That makes the serverless storage meter authoritative rather than
 * an estimate — contrast {@see \App\Modules\Edge\Services\EdgeSiteR2StorageEstimator},
 * which infers Edge storage from deployment metadata.
 *
 * The retention rule leans on an invariant of {@see ServerlessAssetPublisher}:
 * every publish re-uploads the WHOLE build directory, so any object still
 * referenced by the current build had its mtime refreshed by the last publish.
 * "Abandoned" is therefore exactly "older than the Nth-most-recent publish".
 *
 * Cutting on publishes rather than deploys is deliberate. A rollback records a
 * successful deployment but re-deploys an existing artifact without
 * republishing, so a deploy-based cutoff would let a run of rollbacks step
 * past assets that are still being served.
 *
 * DigitalOcean Spaces bills no per-operation fee, so a daily LIST per site
 * costs nothing — which is also why the same pass measures each function's own
 * app bucket ({@see ServerlessAppBucketProvisioner}) while it is here. That
 * figure is recorded, not billed: the storage a customer's app chooses to keep
 * is a different product decision from the build dply publishes for them, and
 * recording it now means pricing it later needs no backfill.
 */
class ServerlessAssetGarbageCollector
{
    /**
     * @return array{sites: int, bytes: int, deleted: int, reclaimed_bytes: int, app_bytes: int}
     */
    public function sweep(bool $dryRun = false): array
    {
        $sites = $this->assetBearingSites();
        $totals = ['sites' => 0, 'bytes' => 0, 'deleted' => 0, 'reclaimed_bytes' => 0, 'app_bytes' => 0];

        foreach ($sites as $site) {
            try {
                $result = $this->sweepSite($site, $dryRun);
            } catch (Throwable $e) {
                // One unreachable prefix must not abandon the rest of the
                // fleet — the storage meter for every other site still needs
                // to land today.
                Log::warning('serverless.assets.gc_failed', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $totals['sites']++;
            $totals['bytes'] += $result['bytes'];
            $totals['deleted'] += $result['deleted'];
            $totals['reclaimed_bytes'] += $result['reclaimed_bytes'];
            $totals['app_bytes'] += $result['app_bytes'];
        }

        return $totals;
    }

    /**
     * Sweep one site: total what it stores, delete what no retained publish
     * still references, and record the measurement on the site.
     *
     * @return array{bytes: int, deleted: int, reclaimed_bytes: int, app_bytes: int}
     */
    public function sweepSite(Site $site, bool $dryRun = false): array
    {
        $disk = $this->disk();
        $cutoff = $this->cutoffFor($site);

        $bytes = 0;
        $deleted = 0;
        $reclaimed = 0;

        foreach ($this->prefixesFor($site) as $prefix) {
            foreach ($disk->getDriver()->listContents($prefix, true) as $item) {
                if (! $item instanceof FileAttributes) {
                    continue;
                }

                $size = $this->sizeOf($disk, $item);
                $modified = $item->lastModified();

                // A null mtime means the adapter did not report one; treat the
                // object as live rather than risk deleting a served file.
                if ($cutoff !== null && $modified !== null && $modified < $cutoff) {
                    if (! $dryRun) {
                        $disk->delete($item->path());
                    }

                    $deleted++;
                    $reclaimed += $size;

                    continue;
                }

                $bytes += $size;
            }
        }

        $appBytes = $this->appBucketBytes($site);

        if (! $dryRun) {
            $this->recordMeasurement($site, $bytes, $appBytes);
        }

        return ['bytes' => $bytes, 'deleted' => $deleted, 'reclaimed_bytes' => $reclaimed, 'app_bytes' => $appBytes ?? 0];
    }

    /**
     * Unix timestamp below which objects are abandoned, or null when the site
     * has not published often enough for anything to have been superseded.
     */
    private function cutoffFor(Site $site): ?int
    {
        $retain = max(1, (int) config('serverless.assets.retain_deploys', 5));
        $publishes = $this->publishLog($site);

        // Fewer publishes than we retain: nothing can be abandoned yet.
        if (count($publishes) < $retain) {
            return null;
        }

        $oldestRetained = $publishes[$retain - 1];

        // Object mtimes come from the storage provider's clock and publish
        // timestamps from ours, so back the cutoff off before deleting.
        $grace = max(0, (int) config('serverless.assets.gc_grace_hours', 2));

        return $oldestRetained->subHours($grace)->getTimestamp();
    }

    /**
     * This site's publish timestamps, newest first.
     *
     * @return list<CarbonImmutable>
     */
    private function publishLog(Site $site): array
    {
        $assets = $site->serverlessConfig()['assets'] ?? [];
        $raw = is_array($assets) ? ($assets['publishes'] ?? []) : [];
        if (! is_array($raw)) {
            return [];
        }

        $parsed = [];
        foreach ($raw as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                $parsed[] = CarbonImmutable::parse($value);
            } catch (Throwable) {
                continue;
            }
        }

        usort($parsed, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $b <=> $a);

        return $parsed;
    }

    /**
     * Both the current prefix and the pre-cutover one, so a site that has not
     * been backfilled yet is still measured and still swept.
     *
     * @return list<string>
     */
    private function prefixesFor(Site $site): array
    {
        return array_values(array_unique(array_filter([
            ServerlessAssetHost::prefix($site),
            ServerlessAssetHost::legacyPrefix($site),
        ])));
    }

    private function sizeOf(FilesystemAdapter $disk, FileAttributes $item): int
    {
        $size = $item->fileSize();
        if ($size !== null) {
            return (int) $size;
        }

        try {
            return (int) $disk->size($item->path());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * What the app itself is storing in its own bucket, or null when it has no
     * dply-provisioned bucket (or the measurement failed).
     *
     * Measured here rather than in its own job because this is already the
     * daily per-site pass that writes the storage meta the collector reads,
     * and Spaces charges nothing for the LIST it costs.
     *
     * Never fails the sweep: the published-asset numbers are the billed ones,
     * and losing them to an unreachable app bucket would be the worse trade.
     */
    private function appBucketBytes(Site $site): ?int
    {
        try {
            return app(ServerlessAppBucketProvisioner::class)->storageBytes($site);
        } catch (Throwable $e) {
            Log::warning('serverless.app_bucket.measure_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Persist the measurements the usage collector reads. Kept on the site
     * rather than written straight to a snapshot so the collector stays a pure
     * roll-up and can run on its own cadence.
     *
     * A null app-bucket figure leaves the previous one in place — "could not
     * measure today" is not "the bucket is empty".
     */
    private function recordMeasurement(Site $site, int $bytes, ?int $appBytes = null): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $assets = is_array($serverless['assets'] ?? null) ? $serverless['assets'] : [];

        $assets['storage_bytes'] = $bytes;
        $assets['storage_measured_at'] = now()->toIso8601String();
        $serverless['assets'] = $assets;

        if ($appBytes !== null) {
            $appBucket = is_array($serverless['app_bucket'] ?? null) ? $serverless['app_bucket'] : [];
            $appBucket['storage_bytes'] = $appBytes;
            $appBucket['storage_measured_at'] = now()->toIso8601String();
            $serverless['app_bucket'] = $appBucket;
        }

        $meta['serverless'] = $serverless;

        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * Managed functions only — the same population the usage collector meters.
     * BYO functions deploy to the customer's own account and dply neither
     * stores nor bills their assets.
     *
     * @return Collection<int, Site>
     */
    private function assetBearingSites(): Collection
    {
        return Site::query()
            ->where('serverless_backend', Site::SERVERLESS_BACKEND_DPLY)
            ->whereIn('status', [Site::STATUS_FUNCTIONS_ACTIVE, Site::STATUS_FUNCTIONS_CONFIGURED])
            ->get(['id', 'organization_id', 'meta']);
    }

    /**
     * The return type does the narrowing: a driver that resolved to something
     * other than a filesystem adapter would TypeError here rather than fail
     * further in with a listContents() call on the wrong object.
     */
    private function disk(): FilesystemAdapter
    {
        return Storage::disk(ServerlessAssetPublisher::DISK);
    }
}
