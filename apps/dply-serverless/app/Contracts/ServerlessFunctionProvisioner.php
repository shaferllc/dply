<?php

namespace App\Contracts;

/**
 * Deploy or update a serverless function on a cloud (AWS Lambda, DO, …).
 * Implementations are provider-specific; this app starts with a local stub only.
 */
interface ServerlessFunctionProvisioner
{
    /**
     * @param  array<string, mixed>  $config  Provider credentials/options (injected by app later).
     * @return array{function_arn: string, revision_id: string, provider: string, runtime: string, artifact_path: string, config_keys: array<int, string>}
     *
     * The returned `config_keys` omits credential key names and includes `credentials_present` when non-empty credentials were passed.
     */
    public function deployFunction(string $name, string $runtime, string $artifactPath, array $config = []): array;
}
