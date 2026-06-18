<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Jobs\RedeployCloudSiteJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Trigger a redeploy of an cloud site without changing image
 * or env. For source-mode sites this re-rolls the latest commit
 * on the configured branch; for image-mode it re-pulls the same
 * image tag.
 *
 *   dply:cloud:redeploy <site>
 *
 * Use this from CI when you want a fresh roll independently of
 * a code or env change — e.g. to clear an unhealthy state on a
 * site that's running the right spec but a stale instance.
 *
 * For image-mode rollouts of a NEW tag, use dply:cloud:deploy
 * (which sets the image first). For env changes, dply:cloud:env.
 */
class CloudRedeployCommand extends Command
{
    protected $signature = 'dply:cloud:redeploy
        {site : Site ID, slug, or name}';

    protected $description = 'Trigger a redeploy of an cloud site without changing image or env vars.';

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

        RedeployCloudSiteJob::dispatch($site->id);
        $this->info(sprintf('Redeploy queued for %s.', $site->name));

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
