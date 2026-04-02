<?php

namespace App\Services\Deploy;

final class KubernetesDeployEngine implements \App\Contracts\DeployEngine
{
    public function __construct(
        private readonly KubernetesManifestBuilder $manifestBuilder,
        private readonly KubernetesKubectlExecutor $kubectlExecutor,
        private readonly LocalDockerKubernetesRuntimeManager $localRuntimeManager,
    ) {}

    public function run(DeployContext $context): array
    {
        $site = $context->site();
        if ($site->runtimeTargetFamily() === 'local_orbstack_kubernetes') {
            $result = $this->localRuntimeManager->deploy($site);
            $siteMeta = is_array($site->meta) ? $site->meta : [];
            $kubernetesRuntime = is_array($siteMeta['kubernetes_runtime'] ?? null) ? $siteMeta['kubernetes_runtime'] : [];
            $namespace = (string) ($kubernetesRuntime['namespace'] ?? 'default');

            $siteMeta['kubernetes_runtime'] = array_merge($kubernetesRuntime, [
                'namespace' => $result['namespace'] ?? $namespace,
                'manifest_yaml' => $result['manifest_yaml'] ?? ($kubernetesRuntime['manifest_yaml'] ?? null),
                'deployment_name' => $result['deployment_name'] ?? ($kubernetesRuntime['deployment_name'] ?? $this->manifestBuilder->deploymentName($site)),
                'kubectl_context' => $result['context'] ?? ($kubernetesRuntime['kubectl_context'] ?? null),
                'last_apply_output' => $result['output'],
                'last_revision_id' => $result['sha'],
                'workspace_path' => $result['workspace_path'] ?? ($kubernetesRuntime['workspace_path'] ?? null),
                'repository_checkout_path' => $result['repository_checkout_path'] ?? ($kubernetesRuntime['repository_checkout_path'] ?? null),
                'generated_manifest_path' => $result['generated_manifest_path'] ?? ($kubernetesRuntime['generated_manifest_path'] ?? null),
                'applied_at' => now()->toIso8601String(),
                'last_deployed_at' => now()->toIso8601String(),
            ]);
            $siteMeta['runtime_target'] = array_merge($site->runtimeTarget(), [
                'status' => $result['status'] ?? 'running',
                'last_deployed_at' => now()->toIso8601String(),
                'last_operation' => 'deploy',
                'last_operation_output' => $result['output'],
                'last_revision_id' => $result['sha'] ?? null,
            ]);

            $site->forceFill(['meta' => $siteMeta])->save();

            return [
                'output' => $result['output'],
                'sha' => $result['sha'],
            ];
        }

        $serverMeta = is_array($site->server?->meta) ? $site->server->meta : [];
        $siteMeta = is_array($site->meta) ? $site->meta : [];
        $kubernetesRuntime = is_array($siteMeta['kubernetes_runtime'] ?? null) ? $siteMeta['kubernetes_runtime'] : [];
        $namespace = (string) ($kubernetesRuntime['namespace'] ?? $serverMeta['kubernetes']['namespace'] ?? 'default');
        $manifest = $this->manifestBuilder->build($site, $namespace);
        $deploymentName = $this->manifestBuilder->deploymentName($site);
        $kubeconfigPath = $this->resolveKubeconfigPath($serverMeta, $kubernetesRuntime);
        $contextName = $this->resolveContext($serverMeta, $kubernetesRuntime);
        $result = $this->kubectlExecutor->deploy(
            $manifest,
            $namespace,
            $deploymentName,
            $kubeconfigPath,
            $contextName,
        );

        $siteMeta['kubernetes_runtime'] = array_merge($kubernetesRuntime, [
            'namespace' => $namespace,
            'manifest_yaml' => $manifest,
            'deployment_name' => $deploymentName,
            'kubectl_context' => $result['context'],
            'last_apply_output' => $result['output'],
            'last_revision_id' => $result['revision'],
            'applied_at' => now()->toIso8601String(),
            'last_deployed_at' => now()->toIso8601String(),
        ]);
        $siteMeta['runtime_target'] = array_merge($site->runtimeTarget(), [
            'status' => 'running',
            'last_deployed_at' => now()->toIso8601String(),
            'last_operation' => 'deploy',
            'last_operation_output' => $result['output'],
            'last_revision_id' => $result['revision'] ?? null,
        ]);

        $site->forceFill(['meta' => $siteMeta])->save();

        return [
            'output' => implode("\n\n", array_filter([
                'Kubernetes deploy applied.',
                $result['output'],
                $manifest,
            ])),
            'sha' => $result['revision'],
        ];
    }

    /**
     * @param  array<string, mixed>  $serverMeta
     * @param  array<string, mixed>  $runtime
     */
    private function resolveKubeconfigPath(array $serverMeta, array $runtime): ?string
    {
        $path = trim((string) ($runtime['kubeconfig_path'] ?? data_get($serverMeta, 'kubernetes.kubeconfig_path', config('kubernetes.kubeconfig_path'))));

        return $path !== '' ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $serverMeta
     * @param  array<string, mixed>  $runtime
     */
    private function resolveContext(array $serverMeta, array $runtime): ?string
    {
        $context = trim((string) ($runtime['context'] ?? data_get($serverMeta, 'kubernetes.context', config('kubernetes.context'))));

        return $context !== '' ? $context : null;
    }
}
