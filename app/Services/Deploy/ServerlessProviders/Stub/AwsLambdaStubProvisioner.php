<?php

declare(strict_types=1);

namespace App\Services\Deploy\ServerlessProviders\Stub;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Services\Deploy\Support\ProvisionerConfigReport;

final class AwsLambdaStubProvisioner implements ServerlessFunctionProvisioner
{
    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        return [
            'function_arn' => 'arn:aws:lambda:us-east-1:000000000000:function:'.rawurlencode($name),
            'revision_id' => 'aws-stub-revision-1',
            'provider' => 'aws',
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }
}
