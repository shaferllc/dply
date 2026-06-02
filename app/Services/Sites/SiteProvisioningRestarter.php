<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Models\Site;
use App\Services\Certificates\CertificateRequestService;
use App\Services\SshConnection;
use App\Services\SshConnectionFactory;

class SiteProvisioningRestarter
{
    public function __construct(
        private readonly SiteWebserverConfigApplier $webserverConfigApplier,
        private readonly TestingHostnameProvisioner $testingHostnameProvisioner,
        private readonly CertificateRequestService $certificateRequestService,
        private readonly SiteProvisioner $siteProvisioner,
        private readonly SshConnectionFactory $sshConnectionFactory,
    ) {}

    public function restart(Site $site): void
    {
        $site->loadMissing(['server', 'domains', 'previewDomains', 'certificates']);

        $this->siteProvisioner->appendLog($site, 'warning', 'restart', 'Restarting site provisioning from a clean slate.');

        // Wrap each cleanup step so a partial failure (e.g. dead provider
        // credential, removed remote, expired token) doesn't abort the
        // whole restart and leave the operator staring at an unchanged
        // error screen.
        $this->safelyRun($site, 'removeServerConfig', fn () => $this->removeServerConfig($site));
        $this->safelyRun($site, 'removeCertificates', fn () => $this->removeCertificates($site));
        $this->safelyRun($site, 'removeTestingHostname', fn () => $this->removeTestingHostname($site));
        $this->safelyRun($site, 'removePlaceholderIndexes', fn () => $this->removePlaceholderIndexes($site));
        $this->safelyRun($site, 'resetLocalState', fn () => $this->resetLocalState($site));

        $site->refresh();

        $this->siteProvisioner->appendLog($site, 'info', 'restart', 'Cleanup finished. Queuing a full provisioning run.');
        $this->siteProvisioner->markQueued($site);

        // If the server was provisioned without a webserver (legacy worker
        // hosts that landed with webserver=none), install Caddy first.
        // InstallServerWebserverJob re-queues ProvisionSiteJob for affected
        // sites once Caddy is up, so the site stays in STATUS_PENDING and
        // the provisioning journey shows real progress instead of jumping
        // back to the failed/empty state.
        $serverWebserver = (string) ($site->server?->meta['webserver'] ?? '');
        if ($serverWebserver === 'none' && $site->server) {
            $meta = is_array($site->server->meta) ? $site->server->meta : [];
            if (! ($meta['webserver_install_pending'] ?? false)) {
                $meta['webserver_install_pending'] = true;
                $site->server->forceFill(['meta' => $meta])->save();
            }
            $this->siteProvisioner->appendLog(
                $site,
                'info',
                'queued',
                'Installing Caddy on the server before this site can be provisioned…',
                ['server_id' => (string) $site->server_id],
            );
            \App\Jobs\InstallServerWebserverJob::dispatch((string) $site->server_id, 'caddy');

            return;
        }

        ProvisionSiteJob::dispatch($site->id);
    }

    /**
     * Run a cleanup callback, logging any throw under the given step name
     * instead of bubbling. Each phase of restart() is "best effort" — we'd
     * rather queue the new provisioning run than abort because a dead DNS
     * credential couldn't delete a record that may already be gone.
     */
    private function safelyRun(Site $site, string $step, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->siteProvisioner->appendLog($site, 'warning', 'restart', sprintf('Cleanup step "%s" failed but restart will continue.', $step), [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function removeServerConfig(Site $site): void
    {
        if (! $site->server || ! $site->server->isReady() || empty($site->server->ssh_private_key)) {
            $this->siteProvisioner->appendLog($site, 'warning', 'restart', 'Skipping server config cleanup because the server is not ready for SSH cleanup.');

            return;
        }

        if ($site->usesFunctionsRuntime() || $site->usesKubernetesRuntime()) {
            return;
        }

        if ($site->usesDockerRuntime() && ! $site->usesVmDockerRuntime()) {
            return;
        }

        try {
            $this->webserverConfigApplier->remove($site);
        } catch (\Throwable $e) {
            $this->siteProvisioner->appendLog($site, 'warning', 'restart', 'Server config cleanup failed.', [
                'webserver' => $site->webserver(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->siteProvisioner->appendLog($site, 'info', 'restart', 'Server config cleanup completed.', [
            'webserver' => $site->webserver(),
        ]);
    }

    private function removeCertificates(Site $site): void
    {
        foreach ($site->certificates as $certificate) {
            $this->certificateRequestService->removeArtifacts($certificate);
            $certificate->delete();
        }

        if ($site->certificates->isNotEmpty()) {
            $this->siteProvisioner->appendLog($site, 'info', 'restart', 'Removed certificate records for a fresh SSL run.');
        }
    }

    private function removeTestingHostname(Site $site): void
    {
        $testingHostname = $site->testingHostname();
        if ($testingHostname === '') {
            return;
        }

        $this->testingHostnameProvisioner->delete($site);

        $this->siteProvisioner->appendLog($site, 'info', 'restart', 'Testing hostname cleanup completed.', [
            'hostname' => $testingHostname,
        ]);
    }

    private function removePlaceholderIndexes(Site $site): void
    {
        if (! in_array($site->type, [SiteType::Php, SiteType::Static], true)) {
            return;
        }

        if (! $site->server || ! $site->server->isReady() || empty($site->server->ssh_private_key)) {
            return;
        }

        $root = rtrim($site->effectiveDocumentRoot(), '/');
        if ($root === '') {
            return;
        }

        try {
            $ssh = $this->sshForSite($site);
            $ssh->exec(sprintf(
                'rm -f %s/index.html %s/index.htm 2>/dev/null || true',
                escapeshellarg($root),
                escapeshellarg($root),
            ), 30);
            $this->siteProvisioner->appendLog($site, 'info', 'restart', 'Cleared placeholder index files from the document root.');
        } catch (\Throwable $e) {
            $this->siteProvisioner->appendLog($site, 'warning', 'restart', 'Could not clear placeholder index files.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resetLocalState(Site $site): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];

        foreach ([
            'caddy_last_output',
            'caddy_cleanup_output',
            'nginx_last_output',
            'nginx_cleanup_output',
            'edge_backend_last_output',
            'edge_backend_port',
            'traefik_backend_port',
            'openlitespeed_last_output',
            'apache_last_output',
            'traefik_last_output',
            'testing_hostname',
            'ssl_last_output',
            'ssl_last_attempt_at',
            'ssl_last_requested_domains',
        ] as $key) {
            unset($meta[$key]);
        }

        unset($meta['provisioning']);

        $site->forceFill([
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'ssl_installed_at' => null,
            'meta' => $meta,
        ])->save();
    }

    private function sshForSite(Site $site): SshConnection
    {
        $server = $site->server;
        if ($server === null) {
            throw new \RuntimeException('Site has no server.');
        }

        if ($server->recoverySshPrivateKey()) {
            $root = $this->sshConnectionFactory->recoveryForServer($server);
            if ($root->connect()) {
                return $root;
            }
        }

        return $this->sshConnectionFactory->forServer($server);
    }
}
