<?php

declare(strict_types=1);

namespace App\Services\Deploy\ServerlessProviders\Stub;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Services\Deploy\Support\ProvisionerConfigReport;

final class DigitalOceanStubProvisioner implements ServerlessFunctionProvisioner
{
    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        return [
            'function_arn' => 'do:function:stub:'.rawurlencode($name),
            'revision_id' => 'digitalocean-stub-revision-1',
            'provider' => 'digitalocean',
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }
}
