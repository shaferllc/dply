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

        $this->removeServerConfig($site);
        $this->removeCertificates($site);
        $this->removeTestingHostname($site);
        $this->removePlaceholderIndexes($site);
        $this->resetLocalState($site);

        $site->refresh();

        $this->siteProvisioner->appendLog($site, 'info', 'restart', 'Cleanup finished. Queuing a full provisioning run.');
        $this->siteProvisioner->markQueued($site);
        ProvisionSiteJob::dispatch($site->id);
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
