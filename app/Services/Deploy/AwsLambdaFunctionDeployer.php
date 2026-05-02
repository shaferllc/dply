<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Server;
use App\Models\Site;

final class AwsLambdaFunctionDeployer
{
    public function __construct(
        private readonly DigitalOceanFunctionsArtifactBuilder $artifactBuilder,
        private readonly ServerlessDeploymentConfigResolver $deploymentConfigResolver,
        private readonly ServerlessProvisionerFactory $provisionerFactory,
        private readonly DeploymentContractBuilder $contractBuilder,
        private readonly DeploymentRevisionTracker $revisionTracker,
    ) {}

    /**
     * @return array{output: string, revision_id: ?string, url: ?string}
     */
    public function deploy(Site $site): array
    {
        $site->loadMissing('server.providerCredential', 'domains');

        $server = $site->server;
        if (! $server instanceof Server || ! $server->isAwsLambdaHost()) {
            throw new \RuntimeException('AWS Lambda deploy requires an AWS Lambda-backed host.');
        }

        $resolvedConfig = $this->deploymentConfigResolver->resolve($site);
        $buildResult = $this->artifactBuilder->build($site);

        $providerConfig = [
            'credentials' => is_array($server->providerCredential?->credentials) ? $server->providerCredential->credentials : [],
            'project' => [
                'settings' => [
                    'aws_region' => data_get($server->meta, 'aws_lambda.region', $server->region ?: 'us-east-1'),
                ],
            ],
        ];

        $functionName = trim((string) ($resolvedConfig['function_name'] ?? $site->id));
        $deployResult = $this->provisionerFactory
            ->make('aws')
            ->deployFunction(
                $functionName !== '' ? $functionName : (string) $site->id,
                (string) $resolvedConfig['runtime'],
                $buildResult['artifact_path'],
                $providerConfig,
            );

        $siteMeta = is_array($site->meta) ? $site->meta : [];
        $serverlessConfig = $site->serverlessConfig();
        $siteMeta['serverless'] = array_merge($serverlessConfig, [
            'target' => Server::HOST_KIND_AWS_LAMBDA,
            'runtime' => $resolvedConfig['runtime'],
            'entrypoint' => $resolvedConfig['entrypoint'],
            'function_name' => $functionName,
            'artifact_path' => $buildResult['artifact_path'],
            'last_deployed_at' => now()->toIso8601String(),
            'last_revision_id' => $deployResult['revision_id'],
            'function_arn' => $deployResult['function_arn'],
            'function_url' => null,
        ]);
        $site->forceFill(['meta' => $siteMeta])->save();
        $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'runtime');

        return [
            'output' => implode("\n", array_filter([
                $buildResult['output'] !== '' ? $buildResult['output'] : null,
                'AWS Lambda deploy completed.',
                'Function: '.$functionName,
                'Runtime: '.(string) $resolvedConfig['runtime'],
                $deployResult['revision_id'] !== '' ? 'Revision: '.$deployResult['revision_id'] : null,
                'ARN: '.$deployResult['function_arn'],
            ])),
            'revision_id' => $deployResult['revision_id'],
            'url' => null,
        ];
    }
}
