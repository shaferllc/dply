<?php

namespace App\Services\Sites;

use App\Models\Site;

class SiteProvisioningCanceller
{
    public function __construct(
        private readonly TestingHostnameProvisioner $testingHostnameProvisioner,
        private readonly SiteWebserverProvisionerRegistry $provisionerRegistry,
        private readonly SiteProvisioner $siteProvisioner,
    ) {}

    public function cancel(Site $site): void
    {
        $site->loadMissing(['server', 'domains']);

        $this->siteProvisioner->appendLog($site, 'warning', 'cancelled', 'Cancelling site provisioning and cleaning up created resources.');

        $this->removeServerConfig($site);
        $this->removeTestingHostname($site);

        $this->siteProvisioner->appendLog($site, 'info', 'cancelled', 'Provisioning cleanup completed. Deleting pending site.');

        $site->domains()->delete();
        $site->delete();
    }

    private function removeServerConfig(Site $site): void
    {
        if (! $site->server || ! $site->server->isReady() || empty($site->server->ssh_private_key)) {
            $this->siteProvisioner->appendLog($site, 'warning', 'cancelled', 'Skipping server config cleanup because the server is not ready for SSH cleanup.');

            return;
        }

        try {
            $this->provisionerRegistry->for($site->webserver())->remove($site);
        } catch (\Throwable $e) {
            $this->siteProvisioner->appendLog($site, 'warning', 'cancelled', 'Server config cleanup failed.', [
                'webserver' => $site->webserver(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->siteProvisioner->appendLog($site, 'info', 'cancelled', 'Server config cleanup completed.', [
            'webserver' => $site->webserver(),
        ]);
    }

    private function removeTestingHostname(Site $site): void
    {
        $testingHostname = $site->testingHostname();
        if ($testingHostname === '') {
            return;
        }

        $this->testingHostnameProvisioner->delete($site);

        $this->siteProvisioner->appendLog($site, 'info', 'cancelled', 'Testing hostname cleanup completed.', [
            'hostname' => $testingHostname,
        ]);
    }
}
