<?php

namespace App\Serverless\Stub;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Serverless\Support\ProvisionerConfigReport;

/**
 * No cloud calls — proves the seam until AWS/DO adapters land.
 */
final class LocalStubProvisioner implements ServerlessFunctionProvisioner
{
    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        return [
            'function_arn' => 'arn:dply:local:function:'.rawurlencode($name),
            'revision_id' => 'local-revision-1',
            'provider' => 'local',
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }
}
