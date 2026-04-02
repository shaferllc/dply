<?php

namespace App\Services\Deploy;

use App\Models\Site;

class SiteRuntimeActionExecutor
{
    public function __construct(
        private readonly LocalDockerRuntimeManager $localDockerManager,
        private readonly LocalDockerKubernetesRuntimeManager $localKubernetesManager,
    ) {}

    /**
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    public function run(Site $site, string $action): array
    {
        return match ($site->runtimeTargetFamily()) {
            'local_orbstack_docker' => $this->runDocker($site, $action),
            'local_orbstack_kubernetes' => $this->runKubernetes($site, $action),
            default => throw new \RuntimeException('Runtime controls are not available for this target yet.'),
        };
    }

    /**
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    private function runDocker(Site $site, string $action): array
    {
        return match ($action) {
            'rebuild' => $this->normalizeDeployResult($this->localDockerManager->deploy($site)),
            'start' => $this->localDockerManager->start($site),
            'stop' => $this->localDockerManager->stop($site),
            'restart' => $this->localDockerManager->restart($site),
            'logs' => $this->localDockerManager->logs($site),
            'destroy' => $this->localDockerManager->destroy($site),
            'inspect' => $this->localDockerManager->inspect($site),
            'status' => $this->localDockerManager->status($site),
            default => throw new \RuntimeException('Unsupported runtime action.'),
        };
    }

    /**
     * @return array{status: string, output: string}
     */
    private function runKubernetes(Site $site, string $action): array
    {
        return match ($action) {
            'rebuild' => $this->normalizeDeployResult($this->localKubernetesManager->deploy($site)),
            'start' => $this->localKubernetesManager->start($site),
            'stop' => $this->localKubernetesManager->stop($site),
            'restart' => $this->localKubernetesManager->restart($site),
            'logs' => $this->localKubernetesManager->logs($site),
            'destroy' => $this->localKubernetesManager->destroy($site),
            'status' => $this->localKubernetesManager->status($site),
            default => throw new \RuntimeException('Unsupported runtime action.'),
        };
    }

    /**
     * @param  array{status: string, output: string}|\ArrayAccess<string, mixed>|array<string, mixed>  $result
     * @return array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}
     */
    private function normalizeDeployResult(array $result): array
    {
        return array_filter([
            'status' => (string) ($result['status'] ?? 'running'),
            'output' => (string) ($result['output'] ?? ''),
            'publication' => is_array($result['publication'] ?? null) ? $result['publication'] : null,
            'runtime_details' => is_array($result['runtime_details'] ?? null) ? $result['runtime_details'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
