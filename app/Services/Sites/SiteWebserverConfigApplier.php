<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;

class SiteWebserverConfigApplier
{
    public function __construct(
        private readonly SiteWebserverProvisionerRegistry $registry,
    ) {}

    /**
     * Apply the webserver config for the site. The optional `$emit` is the
     * console-actions-backed emitter used by the queued apply job; when absent,
     * a no-op emitter is supplied so the provisioner code path is identical.
     */
    public function apply(Site $site, ?ConsoleEmitter $emit = null): string
    {
        $site->loadMissing('server');

        if ($site->usesFunctionsRuntime() || $site->usesKubernetesRuntime()) {
            throw new \RuntimeException('This site runtime does not use managed VM webserver config.');
        }

        if ($site->usesDockerRuntime() && ! $site->usesVmDockerRuntime()) {
            throw new \RuntimeException('This site runtime does not use managed VM webserver config.');
        }

        if (! $site->server || ! $site->server->hostCapabilities()->supportsSsh()) {
            throw new \RuntimeException('This host runtime does not support managed webserver config.');
        }

        $edgeProxy = $site->server->edgeProxy();
        if (is_string($edgeProxy) && in_array($edgeProxy, ['traefik', 'haproxy', 'envoy'], true)) {
            return app(SiteEdgeBackendProvisioner::class)->provision($site, $emit ?? new ConsoleEmitter);
        }

        return $this->registry->for($site->webserver())->provision($site, $emit ?? new ConsoleEmitter);
    }

    public function remove(Site $site): string
    {
        $site->loadMissing('server');

        if ($site->usesFunctionsRuntime() || $site->usesKubernetesRuntime()) {
            throw new \RuntimeException('This site runtime does not use managed VM webserver config.');
        }

        if ($site->usesDockerRuntime() && ! $site->usesVmDockerRuntime()) {
            throw new \RuntimeException('This site runtime does not use managed VM webserver config.');
        }

        if (! $site->server || ! $site->server->hostCapabilities()->supportsSsh()) {
            throw new \RuntimeException('This host runtime does not support managed webserver config.');
        }

        $edgeProxy = $site->server->edgeProxy();
        if (is_string($edgeProxy) && in_array($edgeProxy, ['traefik', 'haproxy', 'envoy'], true)) {
            return app(SiteEdgeBackendProvisioner::class)->remove($site);
        }

        return $this->registry->for($site->webserver())->remove($site);
    }
}
