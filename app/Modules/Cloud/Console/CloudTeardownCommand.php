<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Actions\Cloud\CreateCloudPreviewSite;
use App\Jobs\TeardownCloudSiteJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Tear down an cloud container site.
 *
 *   dply:cloud:teardown <site>
 *   dply:cloud:teardown <site> --with-previews
 *
 * Without --with-previews, the command refuses to run when the
 * site has any live preview deployments — tearing down a parent
 * with orphan previews leaves them pointing at a dead source
 * spec. --with-previews queues teardown for each live preview
 * first, then the parent.
 *
 * No undo. The Site row is kept (status flips to STATUS_ERROR,
 * meta.container.torn_down_at is recorded) so audit history
 * survives, but the backend resource is deleted.
 */
class CloudTeardownCommand extends Command
{
    protected $signature = 'dply:cloud:teardown
        {site : Site ID, slug, or name}
        {--with-previews : Also tear down all live preview deployments under this site}';

    protected $description = 'Tear down an cloud container site (and optionally its previews).';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if ($site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site.");

            return self::FAILURE;
        }

        // Reject tearing down a parent that still has live previews
        // unless --with-previews is given. Otherwise the previews are
        // orphaned: their meta.container.preview_parent_site_id
        // points at a dead row, and they'll keep building from the
        // PR branch with no production sibling to compare against.
        $previews = CreateCloudPreviewSite::listForParent($site);
        if ($previews->isNotEmpty() && ! $this->option('with-previews')) {
            $this->error(sprintf(
                'Site has %d live preview(s). Re-run with --with-previews to also tear them down, or tear them down individually first via dply:cloud:preview:teardown.',
                $previews->count(),
            ));

            return self::FAILURE;
        }

        if ($this->option('with-previews')) {
            foreach ($previews as $preview) {
                TeardownCloudSiteJob::dispatch($preview->id);
            }
            if ($previews->isNotEmpty()) {
                $this->info(sprintf('Queued teardown for %d preview(s).', $previews->count()));
            }
        }

        TeardownCloudSiteJob::dispatch($site->id);
        $this->info(sprintf('Teardown queued for %s.', $site->name));

        return self::SUCCESS;
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
