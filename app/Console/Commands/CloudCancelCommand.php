<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use Illuminate\Console\Command;

/**
 * Cancel an in-progress deploy on a Cloud container site.
 *
 *   dply:cloud:cancel <site>
 *
 * Resolves the backend (currently only DO App Platform supports
 * cancel), finds the site's in-progress deployment via the backend's
 * getApp call, and POSTs the cancel endpoint. Idempotent — already-
 * terminal deployments report nothing-to-cancel without erroring.
 */
class CloudCancelCommand extends Command
{
    protected $signature = 'dply:cloud:cancel
        {site : Site ID, slug, or name}';

    protected $description = 'Cancel the in-progress deploy on a Cloud container site (if any).';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }
        if ($site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site.");

            return self::FAILURE;
        }

        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            $this->error('No backend or credential resolved for this site.');

            return self::FAILURE;
        }

        try {
            $canceled = $backend->cancelInProgressDeployment($site, $credential);
        } catch (\Throwable $e) {
            $this->error('Cancel failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($canceled) {
            $this->info("Canceled in-progress deploy for {$site->name}.");
        } else {
            $this->warn("No in-progress deploy to cancel for {$site->name}.");
        }

        return self::SUCCESS;
    }
}
