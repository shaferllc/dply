<?php

namespace App\Serverless\Stub;

use App\Contracts\ServerlessFunctionProvisioner;

/**
 * No DigitalOcean API calls — placeholder until DO Functions adapter is wired.
 */
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
            'config_keys' => array_keys($config),
        ];
    }
}
