<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\External;

use App\Models\ExternalSecretStore;
use RuntimeException;

/**
 * Maps an {@see ExternalSecretStore} to its {@see SecretStoreDriver}. Resolved
 * from the container so tests can bind a fake factory (or fake drivers) without
 * touching the network.
 */
class SecretStoreDriverFactory
{
    public function for(ExternalSecretStore $store): SecretStoreDriver
    {
        return match ($store->driver) {
            ExternalSecretStore::DRIVER_VAULT => new VaultDriver,
            ExternalSecretStore::DRIVER_AWS_SM => new AwsSecretsManagerDriver,
            ExternalSecretStore::DRIVER_DOPPLER => new DopplerDriver,
            default => throw new RuntimeException("Unknown external secret store driver '{$store->driver}'."),
        };
    }
}
