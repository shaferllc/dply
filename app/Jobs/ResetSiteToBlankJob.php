<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteWebserverConfigApplier;
use App\Services\SshConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Wipe a site's deployed application from the server and return it to a bare
 * "splash page" state, keeping the site shell intact (vhost, testing hostname,
 * certificates, domains). Backs the "Disconnect repository & start over" action
 * so a site can be re-pointed at a different repo/app from a clean slate.
 *
 * The caller clears the site's repo fields + last_deploy_at BEFORE dispatching,
 * so the webserver re-apply installs the splash placeholder again (its first
 * apply / no-deploy gate keys on those).
 */
class ResetSiteToBlankJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $siteId) {}

    public function handle(SiteWebserverConfigApplier $webserver, SiteProvisioner $provisioner): void
    {
        $site = Site::query()->with(['server', 'domains'])->find($this->siteId);
        if (! $site || ! $site->server) {
            return;
        }

        $base = rtrim((string) $site->effectiveRepositoryPath(), '/');
        // Hard guard: only ever rm a path under the dply home root, never '/'.
        if ($base === '' || ! str_starts_with($base, '/home/') || $base === '/home') {
            Log::warning('ResetSiteToBlankJob: refusing unsafe repo base', ['site_id' => $site->id, 'base' => $base]);

            return;
        }

        $provisioner->appendLog($site, 'warning', 'reset', 'Removing the deployed application and returning the site to a blank splash page.');

        try {
            $ssh = new SshConnection($site->server);
            // Wipe the entire release/repo tree (code, .env, shared storage) so
            // the next app genuinely starts from nothing. Privileged because
            // release dirs are written by privileged deploy/provision steps.
            $ssh->exec('sudo rm -rf '.escapeshellarg($base).' 2>&1', 600);

            // Also remove the seeded local bare origin (lives alongside the base
            // at <base>.git, created by ScaffoldRepoSeeder) so a re-created
            // same-slug site never inherits the old commit history.
            $bareOrigin = $base.'.git';
            $ssh->exec('sudo rm -rf '.escapeshellarg($bareOrigin).' 2>&1', 120);

            // Re-apply the webserver config: recreates the doc root and installs
            // the splash placeholder (the per-engine first-apply / no-deploy gate
            // sees an empty doc root + last_deploy_at=null and writes it).
            $webserver->apply($site);

            $provisioner->appendLog($site, 'info', 'reset', 'Site is back to a blank splash page — connect a repository or pick an app to start again.');
        } catch (\Throwable $e) {
            $provisioner->appendLog($site, 'error', 'reset', 'Reset failed: '.$e->getMessage());
            Log::warning('ResetSiteToBlankJob failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
