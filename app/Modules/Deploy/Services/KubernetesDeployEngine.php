<?php

namespace App\Modules\Deploy\Services;

use App\Contracts\DeployEngine;

final class KubernetesDeployEngine implements DeployEngine
{
    public function __construct(
        private readonly KubernetesManifestBuilder $manifestBuilder,
        private readonly KubernetesKubectlExecutor $kubectlExecutor,
        private readonly LocalDockerKubernetesRuntimeManager $localRuntimeManager,
        private readonly DeploymentContractBuilder $contractBuilder,
        private readonly DeploymentRevisionTracker $revisionTracker,
    ) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function run(DeployContext $context): array
    {
        $site = $context->site();
        if ($site->runtimeTargetFamily() === 'local_orbstack_kubernetes') {
            $result = $this->localRuntimeManager->deploy($site);
            $siteMeta = ($site->meta );
            $kubernetesRuntime = is_array($siteMeta['kubernetes_runtime'] ?? null) ? $siteMeta['kubernetes_runtime'] : [];
            $namespace = (string) ($kubernetesRuntime['namespace'] ?? 'default');

            $siteMeta['kubernetes_runtime'] = array_merge($kubernetesRuntime, [
                'namespace' => $result['namespace'] ?? $namespace,
                'manifest_yaml' => $result['manifest_yaml'] ?? ($kubernetesRuntime['manifest_yaml']),
                'deployment_name' => $result['deployment_name'] ?? ($kubernetesRuntime['deployment_name'] ?? $this->manifestBuilder->deploymentName($site)),
                'kubectl_context' => $result['context'] ?? ($kubernetesRuntime['kubectl_context'] ?? null),
                'last_apply_output' => $result['output'],
                'last_revision_id' => $result['sha'],
                'workspace_path' => $result['workspace_path'] ?? ($kubernetesRuntime['workspace_path']),
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
            $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'runtime');

            return [
                'output' => $result['output'],
                'sha' => $result['sha'],
            ];
        }

        $serverMeta = is_array($site->server?->meta) ? $site->server->meta : [];
        $siteMeta = ($site->meta );
        $kubernetesRuntime = is_array($siteMeta['kubernetes_runtime'] ?? null) ? $siteMeta['kubernetes_runtime'] : [];
        $namespace = (string) ($kubernetesRuntime['namespace'] ?? $serverMeta['kubernetes']['namespace'] ?? 'default');
        $manifest = $this->manifestBuilder->build($site, $namespace);
        $deploymentName = $this->manifestBuilder->deploymentName($site);
        $kubeconfigPath = $this->resolveKubeconfigPath($serverMeta, $kubernetesRuntime);
        $contextName = $this->resolveContext($serverMeta, $kubernetesRuntime);

        // Servers registered/created through dply store the kubeconfig YAML
        // contents in meta.kubernetes.kubeconfig (the poller writes it). The
        // kubectl CLI needs a file path, so materialise to a 0600 temp file
        // scoped to this deploy and delete it after. Falls through to the
        // resolved path above when meta has no inline kubeconfig (legacy
        // servers where the operator set kubernetes.kubeconfig_path manually).
        $materialisedTempPath = null;
        if ($kubeconfigPath === null) {
            $inlineYaml = (string) ($serverMeta['kubernetes']['kubeconfig'] ?? '');
            if (trim($inlineYaml) !== '') {
                $materialisedTempPath = $this->materialiseKubeconfig($inlineYaml);
                $kubeconfigPath = $materialisedTempPath;
            }
        }

        try {
            $result = $this->kubectlExecutor->deploy(
                $manifest,
                $namespace,
                $deploymentName,
                $kubeconfigPath,
                $contextName,
            );
        } finally {
            if ($materialisedTempPath !== null && is_file($materialisedTempPath)) {
                @unlink($materialisedTempPath);
            }
        }

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
        $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'runtime');

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
     * @param  array<string, mixed> $serverMeta
     * @param  array<string, mixed> $runtime
     */
    private function resolveKubeconfigPath(array $serverMeta, array $runtime): ?string
    {
        $path = trim((string) ($runtime['kubeconfig_path'] ?? data_get($serverMeta, 'kubernetes.kubeconfig_path', config('kubernetes.kubeconfig_path'))));

        return $path !== '' ? $path : null;
    }

    /**
     * @param  array<string, mixed> $serverMeta
     * @param  array<string, mixed> $runtime
     */
    private function resolveContext(array $serverMeta, array $runtime): ?string
    {
        $context = trim((string) ($runtime['context'] ?? data_get($serverMeta, 'kubernetes.context', config('kubernetes.context'))));

        return $context !== '' ? $context : null;
    }

    /**
     * Write the supplied kubeconfig YAML to a 0600 temp file and return its
     * absolute path. Caller is responsible for unlinking after kubectl runs.
     * The file lives in sys_get_temp_dir() with a randomised name so two
     * concurrent deploys can't collide on it.
     */
    private function materialiseKubeconfig(string $yaml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dply-kubeconfig-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temp file for the kubeconfig.');
        }
        // tempnam creates the file with 0600 on POSIX which is what we want —
        // bearer creds inside. Belt-and-suspenders chmod in case of umask
        // weirdness on the host.
        @chmod($path, 0600);
        if (file_put_contents($path, $yaml) === false) {
            @unlink($path);
            throw new \RuntimeException('Could not write the kubeconfig to the temp file.');
        }

        return $path;
    }
}
