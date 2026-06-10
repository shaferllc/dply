<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Models\Server;
use RuntimeException;

/**
 * Dispatches the provider's normal VM provisioning job for a freshly-created
 * Server row. Shared by the multi-backend provisioners (web backends + the LB
 * host) so the provider matrix lives in one place. The provider job generates
 * its own SSH keys and creates the box.
 */
class ServerProvisioningDispatcher
{
    public function dispatch(Server $server): void
    {
        match ($server->provider) {
            ServerProvider::Hetzner => ProvisionHetznerServerJob::dispatch($server),
            ServerProvider::DigitalOcean => ProvisionDigitalOceanDropletJob::dispatch($server),
            ServerProvider::Linode => ProvisionLinodeServerJob::dispatch($server),
            ServerProvider::Vultr => ProvisionVultrServerJob::dispatch($server),
            ServerProvider::UpCloud => ProvisionUpCloudServerJob::dispatch($server),
            ServerProvider::Aws => ProvisionAwsEc2ServerJob::dispatch($server),
            ServerProvider::Azure => ProvisionAzureServerJob::dispatch($server),
            ServerProvider::Oracle => ProvisionOracleServerJob::dispatch($server),
            default => throw new RuntimeException(__('Provisioning a :provider server is not supported yet.', [
                'provider' => $server->provider->value,
            ])),
        };
    }
}
