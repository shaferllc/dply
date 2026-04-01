<?php

namespace App\Services\Deploy;

final class KubernetesDeployEngine implements \App\Contracts\DeployEngine
{
    public function __construct(
        private readonly KubernetesManifestBuilder $manifestBuilder,
    ) {}

    public function run(DeployContext $context): array
    {
        $site = $context->site();
        $serverMeta = is_array($site->server?->meta) ? $site->server->meta : [];
        $siteMeta = is_array($site->meta) ? $site->meta : [];
        $kubernetesRuntime = is_array($siteMeta['kubernetes_runtime'] ?? null) ? $siteMeta['kubernetes_runtime'] : [];
        $namespace = (string) ($kubernetesRuntime['namespace'] ?? $serverMeta['kubernetes']['namespace'] ?? 'default');
        $manifest = $this->manifestBuilder->build($site, $namespace);

        $siteMeta['kubernetes_runtime'] = array_merge($kubernetesRuntime, [
            'namespace' => $namespace,
            'manifest_yaml' => $manifest,
            'last_deployed_at' => now()->toIso8601String(),
        ]);

        $site->forceFill(['meta' => $siteMeta])->save();

        return [
            'output' => "Kubernetes deploy prepared.\n\n".$manifest,
            'sha' => null,
        ];
    }
}
