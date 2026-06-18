<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Jobs\InstallServerWebserverJob;
use App\Jobs\ProvisionSiteJob;
use App\Models\Site;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;

/**
 * Provisions a bare site's *foundation* — system user, deploy dir, webserver
 * vhost serving the default page, testing hostname — without any app code. This
 * is what makes a freshly-created site real and reachable so services can be
 * configured against it before a repo is connected (the services-first flow,
 * {@see project_services_first_site_creation}). Shared by the create flow's
 * bare-create and the choose-app "Blank / Skip" path.
 */
class SiteFoundationProvisioner
{
    public function __construct(private readonly SiteProvisioner $siteProvisioner) {}

    /**
     * Seed the runtime's default deploy pipeline and queue foundation
     * provisioning. On a webserver-less ("none") host, install Caddy first —
     * that job re-queues ProvisionSiteJob once the webserver is up, so the
     * provisioning journey shows real progress instead of flipping straight to
     * "ready" with no vhost.
     */
    public function provision(Site $site, string $runtime = 'php', ?string $framework = null): void
    {
        app(SiteDeployPipelineManager::class)->seedRuntimeDefaults(
            $site,
            $runtime,
            $framework !== '' ? $framework : null,
        );

        $site->loadMissing(['server', 'domains']);
        $server = $site->server;
        if ($server === null) {
            return;
        }

        $this->siteProvisioner->markQueued($site);

        $serverWebserver = (string) ($server->meta['webserver'] ?? '');
        if ($serverWebserver === 'none') {
            $meta = is_array($server->meta) ? $server->meta : [];
            if (! ($meta['webserver_install_pending'] ?? false)) {
                $meta['webserver_install_pending'] = true;
                $server->forceFill(['meta' => $meta])->save();
            }
            $this->siteProvisioner->appendLog(
                $site,
                'info',
                'queued',
                __('Installing Caddy on the server before this site can be provisioned…'),
                ['server_id' => (string) $server->id],
            );
            InstallServerWebserverJob::dispatch((string) $server->id, 'caddy');

            return;
        }

        ProvisionSiteJob::dispatch((string) $site->id);
    }
}
