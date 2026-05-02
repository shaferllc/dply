<?php

namespace App\Services\Sites;

use App\Services\Sites\Contracts\SiteRuntimeProvisioner;

class SiteRuntimeProvisionerRegistry
{
    /**
     * @param  iterable<SiteRuntimeProvisioner>  $provisioners
     */
    public function __construct(
        private readonly iterable $provisioners,
    ) {}

    public function for(string $runtimeProfile): SiteRuntimeProvisioner
    {
        foreach ($this->provisioners as $provisioner) {
            if ($provisioner->runtimeProfile() === $runtimeProfile) {
                return $provisioner;
            }
        }

        throw new \RuntimeException('Unsupported runtime profile ['.$runtimeProfile.'] for site provisioning.');
    }
}
