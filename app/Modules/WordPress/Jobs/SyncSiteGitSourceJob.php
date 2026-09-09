<?php

declare(strict_types=1);

namespace App\Modules\WordPress\Jobs;

use App\Models\SiteGitSource;
use App\Modules\WordPress\Materializers\GitSourceMaterializerFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Clones (or composer-requires) one theme/plugin repo onto its site.
 *
 * Queued rather than inline because it is SSH: a git clone against a private
 * repo takes as long as it takes, and a Livewire request is the wrong place to
 * hold that open.
 *
 * Deliberately NOT ShouldBeUnique. A stale unique lock silently wedges every
 * later sync of the same source with no visible failure, which is a worse
 * outcome than two overlapping syncs — and the materializers converge anyway
 * (fetch + reset, or composer config by key), so a double run is harmless.
 */
class SyncSiteGitSourceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $sourceId, public bool $remove = false) {}

    public function handle(GitSourceMaterializerFactory $factory): void
    {
        $source = SiteGitSource::query()->with('site.server')->find($this->sourceId);

        if ($source === null) {
            // Removed between dispatch and run — nothing to do, not an error.
            return;
        }

        // The site itself cannot be missing — site_git_sources cascades on
        // delete — but a site can outlive its server row.
        $site = $source->site;

        if ($site->server === null) {
            $source->markError('The server backing this site no longer exists.');

            return;
        }

        if (! $factory->supports($site)) {
            $source->markError('Theme and plugin repositories are only supported on WordPress sites.');

            return;
        }

        $materializer = $factory->for($site);

        try {
            if ($this->remove) {
                $materializer->remove($source);
                $source->delete();

                return;
            }

            $source->markSyncing();
            $materializer->sync($source);
        } catch (\Throwable $e) {
            Log::warning('WordPress git source sync failed', [
                'site_git_source_id' => $source->id,
                'site_id' => $site->getKey(),
                'layout' => $factory->layoutOf($site),
                'remove' => $this->remove,
                'error' => $e->getMessage(),
            ]);

            // The row keeps the error so the panel can show it; a removal that
            // fails on the box keeps its row rather than orphaning files.
            $source->markError($e->getMessage());
        }
    }
}
