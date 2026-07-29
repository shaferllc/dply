<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\ServerlessProviders\Stub;

use App\Modules\Serverless\Contracts\ServerlessFunctionProvisioner;
use App\Modules\Deploy\Services\Support\ProvisionerConfigReport;

final class RoadmapStubProvisioner implements ServerlessFunctionProvisioner
{
    public function __construct(
        private readonly string $provider,
        private readonly string $functionIdPrefix,
        private readonly string $revisionId,
    ) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
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
