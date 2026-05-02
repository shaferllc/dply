<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
use App\Support\Deployment\DeploymentContract;

final class DeploymentContractBuilder
{
    public function __construct(
        private readonly DeploymentSecretInventory $secretInventory,
        private readonly SiteResourceBindingResolver $resourceBindingResolver,
        private readonly DeploymentRevisionTracker $revisionTracker,
    ) {}

    public function build(Site $site): DeploymentContract
    {
        $site->loadMissing(['server', 'domains', 'environmentVariables', 'workspace.variables']);

        $environment = $this->runtimeEnvironment($site);
        $defaultPath = '/var/www/'.trim((string) ($site->slug ?: $site->name ?: 'site'), '/');
        $documentRoot = (string) ($site->document_root ?: $defaultPath);
        $repositoryPath = (string) ($site->repository_path ?: $documentRoot);
        $effectiveEnvDirectory = $site->isAtomicDeploys()
            ? rtrim($repositoryPath, '/').'/current'
            : rtrim($repositoryPath, '/');
        $target = [
            'runtime_profile' => $site->runtimeProfile(),
            'runtime_target' => $site->runtimeTarget(),
            'family' => $site->runtimeTargetFamily(),
            'mode' => $site->runtimeTargetMode(),
            'platform' => $site->runtimeTargetPlatform(),
            'provider' => $site->runtimeTargetProvider(),
            'webserver' => $site->webserver(),
            'identity_method' => $this->identityMethod($site),
        ];
        $config = [
            'environment_name' => (string) ($site->deployment_environment ?: 'production'),
            'document_root' => $documentRoot,
            'repository_path' => $repositoryPath,
            'effective_env_directory' => $effectiveEnvDirectory,
            'repository_subdirectory' => $site->runtimeRepositorySubdirectory(),
            'app_port' => $site->app_port,
            'environment' => $environment,
            'build_command' => trim((string) data_get($site->serverlessResolvedConfig(), 'build_command', '')),
            'healthcheck_url' => $site->visitUrl(),
        ];
        $artifacts = [
            'docker_runtime' => is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [],
            'kubernetes_runtime' => is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [],
            'serverless' => $site->serverlessConfig(),
        ];

        $draft = new DeploymentContract(
            target: $target,
            config: $config,
            secrets: $this->secretInventory->forSite($site),
            artifacts: $artifacts,
            status: [],
            resourceBindings: $this->resourceBindingResolver->forSite($site),
        );

        $currentRevision = $draft->revision();
        $lastAppliedRuntimeRevision = $this->revisionTracker->appliedRevision($site, 'runtime');

        return new DeploymentContract(
            target: $target,
            config: $config,
            secrets: $draft->secrets,
            artifacts: $artifacts,
            status: [
                'site_status' => (string) $site->status,
                'runtime_status' => (string) data_get($site->runtimeTarget(), 'status', 'pending'),
                'provisioning_state' => $site->provisioningState(),
                'ready_hostname' => $site->provisionedHostname() ?? $site->testingHostname() ?: null,
                'ready_url' => $site->provisionedUrl() ?? $site->visitUrl(),
                'last_applied_runtime_revision' => $lastAppliedRuntimeRevision,
                'current_runtime_revision' => $currentRevision,
                'runtime_drifted' => $lastAppliedRuntimeRevision !== null && $lastAppliedRuntimeRevision !== $currentRevision,
                'last_deployed_at' => (string) data_get($site->runtimeTarget(), 'last_deployed_at', ''),
            ],
            resourceBindings: $draft->resourceBindings,
        );
    }

    /**
     * @return array<string, string>
     */
    private function runtimeEnvironment(Site $site): array
    {
        return $this->secretInventory->effectiveEnvironmentMapForSite($site);
    }

    private function identityMethod(Site $site): string
    {
        return match ($site->runtimeTargetMode()) {
            'serverless' => 'provider_api',
            'docker', 'kubernetes' => $site->usesLocalDockerHostRuntime() ? 'local_runtime' : 'ssh_or_cluster_api',
            default => 'ssh',
        };
    }
}
