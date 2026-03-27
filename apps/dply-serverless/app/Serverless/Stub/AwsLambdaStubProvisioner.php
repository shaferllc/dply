<?php

namespace App\Serverless\Stub;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Serverless\Support\ProvisionerConfigReport;

/**
 * No AWS API calls — placeholder until a Lambda SDK adapter is wired.
 */
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
