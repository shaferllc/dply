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
 * costs nothing.
 */
class ServerlessAssetGarbageCollector
{
    /**
     * @return array{sites: int, bytes: int, deleted: int, reclaimed_bytes: int}
     */
    public function sweep(bool $dryRun = false): array
    {
        $sites = $this->assetBearingSites();
        $totals = ['sites' => 0, 'bytes' => 0, 'deleted' => 0, 'reclaimed_bytes' => 0];

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
        }

        return $totals;
    }

    /**
     * Sweep one site: total what it stores, delete what no retained publish
     * still references, and record the measurement on the site.
     *
     * @return array{bytes: int, deleted: int, reclaimed_bytes: int}
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

        if (! $dryRun) {
            $this->recordMeasurement($site, $bytes);
        }

        return ['bytes' => $bytes, 'deleted' => $deleted, 'reclaimed_bytes' => $reclaimed];
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
     * Persist the measurement the usage collector reads. Kept on the site
     * rather than written straight to a snapshot so the collector stays a pure
     * roll-up and can run on its own cadence.
     */
    private function recordMeasurement(Site $site, int $bytes): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $assets = is_array($serverless['assets'] ?? null) ? $serverless['assets'] : [];

        $assets['storage_bytes'] = $bytes;
        $assets['storage_measured_at'] = now()->toIso8601String();

        $serverless['assets'] = $assets;
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

    private function disk(): FilesystemAdapter
    {
        $disk = Storage::disk(ServerlessAssetPublisher::DISK);

        if (! $disk instanceof FilesystemAdapter) {
            throw new \RuntimeException('Serverless asset disk is not a filesystem adapter.');
        }

        return $disk;
    }
}
