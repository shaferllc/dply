<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeBuildCache;
use App\Modules\Edge\Services\EdgeBuildRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uploads the Edge build cache (node_modules + framework caches) to R2
 * after publish so the tar+upload doesn't sit on the deploy critical path.
 *
 * The build runner leaves `checkout` on disk when async snapshot is enabled;
 * this job snapshots, prunes, then deletes the checkout tree.
 */
class SnapshotEdgeBuildCacheJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public string $siteId,
        public string $checkoutPath,
        public string $cacheKey,
        public ?string $checkoutRootToDelete = null,
    ) {
        $this->onQueue((string) config('edge.build.queue', 'dply-provision'));
    }

    public function handle(EdgeBuildCache $cache): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            $this->cleanupCheckout();

            return;
        }

        if (! is_dir($this->checkoutPath)) {
            Log::info('Edge cache snapshot skipped — checkout gone', [
                'site_id' => $this->siteId,
                'checkout' => $this->checkoutPath,
            ]);

            return;
        }

        try {
            $result = $cache->snapshot($this->checkoutPath, null, $this->cacheKey, $site);
            if ($result['ok']) {
                $deleted = $cache->prune($site);
                Log::info('Edge cache snapshot uploaded', [
                    'site_id' => $this->siteId,
                    'cache_key' => $this->cacheKey,
                    'message' => $result['message'],
                    'pruned' => $deleted,
                ]);
            } else {
                Log::info('Edge cache snapshot skipped', [
                    'site_id' => $this->siteId,
                    'message' => $result['message'],
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            $this->cleanupCheckout();
        }
    }

    private function cleanupCheckout(): void
    {
        $root = $this->checkoutRootToDelete ?: $this->checkoutPath;
        if ($root === '' || ! is_dir($root)) {
            return;
        }

        $buildRoot = EdgeBuildRunner::buildRoot();
        if (! str_starts_with($root, rtrim($buildRoot, '/').'/')) {
            return;
        }

        File::deleteDirectory($root);

        // Drop empty workdir leftovers (out/ already removed by publish).
        $workRoot = dirname($root);
        if (is_dir($workRoot) && str_starts_with($workRoot, rtrim($buildRoot, '/').'/')) {
            $left = array_diff(scandir($workRoot) ?: [], ['.', '..']);
            if ($left === []) {
                File::deleteDirectory($workRoot);
            }
        }
    }
}
