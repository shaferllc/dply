<?php

namespace App\Services\Sites;

use App\Services\Sites\Contracts\SiteWebserverProvisioner;

class SiteWebserverProvisionerRegistry
{
    /**
     * @param  iterable<SiteWebserverProvisioner>  $provisioners
     */
    public function __construct(
        private readonly iterable $provisioners,
    ) {}

    public function for(string $webserver): SiteWebserverProvisioner
    {
        foreach ($this->provisioners as $provisioner) {
            if ($provisioner->webserver() === $webserver) {
                return $provisioner;
            }
        }

        throw new \RuntimeException('Unsupported webserver ['.$webserver.'] for site provisioning.');
    }
}
