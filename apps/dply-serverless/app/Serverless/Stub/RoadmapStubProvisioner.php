<?php

namespace App\Serverless\Stub;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Serverless\Support\ProvisionerConfigReport;

/**
 * No cloud API calls — placeholder for §6 roadmap targets until provider SDKs land.
 */
final class RoadmapStubProvisioner implements ServerlessFunctionProvisioner
{
    public function __construct(
        private readonly string $provider,
        private readonly string $functionIdPrefix,
        private readonly string $revisionId,
    ) {}

    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array
    {
        return [
            'function_arn' => $this->functionIdPrefix.rawurlencode($name),
            'revision_id' => $this->revisionId,
            'provider' => $this->provider,
            'runtime' => $runtime,
            'artifact_path' => $artifactPath,
            'config_keys' => ProvisionerConfigReport::safeConfigKeys($config),
        ];
    }
}
