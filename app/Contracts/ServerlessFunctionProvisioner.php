<?php

namespace App\Contracts;

/**
 * Deploy or update a serverless function on a cloud provider.
 */
interface ServerlessFunctionProvisioner
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{function_arn: string, revision_id: string, provider: string, runtime: string, artifact_path: string, config_keys: array<int, string>}
     */
    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array;
}
