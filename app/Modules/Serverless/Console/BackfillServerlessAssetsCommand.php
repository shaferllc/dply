<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Copy already-published assets from the control plane's local store into the
 * object-storage bucket, renaming each site's prefix from its site id to its
 * asset label.
 *
 *   php artisan dply:serverless:backfill-assets
 *   php artisan dply:serverless:backfill-assets --dry-run
 *   php artisan dply:serverless:backfill-assets --site=01J...
 *
 * Safe to run before flipping DPLY_SERVERLESS_ASSET_CDN_ENABLED on, and safe
 * to re-run. Live sites keep working throughout: their deployed ASSET_URL
 * still points at the same-origin /build route, and that route reads through
 * the disk — so once this has copied the files, it serves them from the bucket
 * without anything being redeployed. Each site moves to its CDN hostname
 * naturally on its next deploy.
 */
class BackfillServerlessAssetsCommand extends Command
{
    protected $signature = 'dply:serverless:backfill-assets
                            {--site= : Only backfill this site id}
                            {--dry-run : Report what would be copied without writing}';

    protected $description = 'Copy published serverless assets onto the object-storage disk under their new prefix.';

    /** The disk assets lived on before they moved to object storage. */
    private const LEGACY_DISK = 'site_assets';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        // Without a configured driver the provider aliases the asset disk onto
        // the legacy local root, so source and target would be the same tree
        // and this would copy a site's files on top of themselves.
        if (blank(config('filesystems.disks.'.ServerlessAssetPublisher::DISK.'.driver'))) {
            $this->warn('The serverless asset disk still resolves to the local store — set SERVERLESS_ASSETS_DISK_DRIVER first.');

            return self::FAILURE;
        }

        $source = Storage::disk(self::LEGACY_DISK);
        $target = Storage::disk(ServerlessAssetPublisher::DISK);

        $sites = $this->sites();
        $copied = 0;
        $skipped = 0;
        $bytes = 0;

        foreach ($sites as $site) {
            $legacyPrefix = ServerlessAssetHost::legacyPrefix($site);
            $newPrefix = ServerlessAssetHost::prefix($site);

            if ($newPrefix === null) {
                $this->line("  skipped {$site->id}: no asset label allocated yet");
                $skipped++;

                continue;
            }

            $files = $source->allFiles($legacyPrefix);
            if ($files === []) {
                continue;
            }

            foreach ($files as $path) {
                $relative = ltrim(substr($path, strlen($legacyPrefix)), '/');
                if ($relative === '') {
                    continue;
                }

                $key = $newPrefix.'/'.$relative;

                // Re-runnable: an object already at the destination is left
                // alone, so a partial run resumes rather than re-uploading.
                if ($target->exists($key)) {
                    $skipped++;

                    continue;
                }

                $size = (int) $source->size($path);

                if (! $dryRun) {
                    $publisher = app(ServerlessAssetPublisher::class);
                    $target->put($key, (string) $source->get($path), [
                        'visibility' => 'public',
                        'ContentType' => $publisher->mimeFor($relative),
                        'CacheControl' => preg_match('/\.[a-f0-9]{8,}\./', $relative) === 1
                            ? 'public, max-age=31536000, immutable'
                            : 'public, max-age=3600',
                    ]);
                }

                $copied++;
                $bytes += $size;
            }

            $this->line("  {$site->id} → {$newPrefix}");
        }

        $this->info(sprintf(
            '%s %d file(s) (%s), %d already present.',
            $dryRun ? '[dry-run] Would copy' : 'Copied',
            $copied,
            $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 2).' GiB' : round($bytes / 1024 ** 2, 1).' MiB',
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Site>
     */
    private function sites()
    {
        $siteId = trim((string) $this->option('site'));

        return Site::query()
            ->where('serverless_backend', Site::SERVERLESS_BACKEND_DPLY)
            ->when($siteId !== '', fn ($query) => $query->whereKey($siteId))
            ->get(['id', 'organization_id', 'meta']);
    }
}
