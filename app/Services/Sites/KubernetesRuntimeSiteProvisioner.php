<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Deploy\KubernetesManifestBuilder;
use App\Services\Sites\Contracts\SiteRuntimeProvisioner;

final class KubernetesRuntimeSiteProvisioner implements SiteRuntimeProvisioner
{
    public function __construct(
        private readonly KubernetesManifestBuilder $manifestBuilder,
    ) {}

    public function runtimeProfile(): string
    {
        return 'kubernetes_web';
    }

    public function provision(Site $site): void
    {
        $serverMeta = is_array($site->server?->meta) ? $site->server->meta : [];
        $meta = is_array($site->meta) ? $site->meta : [];
        $runtime = is_array($meta['kubernetes_runtime'] ?? null) ? $meta['kubernetes_runtime'] : [];
        $namespace = (string) ($runtime['namespace'] ?? data_get($serverMeta, 'kubernetes.namespace', 'default'));

        $meta['kubernetes_runtime'] = array_merge($runtime, [
            'namespace' => $namespace,
            'manifest_yaml' => $this->manifestBuilder->build($site, $namespace),
            'configured_at' => now()->toIso8601String(),
        ]);

        $site->forceFill(['meta' => $meta])->save();
    }

    public function readyResult(Site $site): array
    {
        $site->loadMissing('domains');

        return [
            'ok' => true,
            'hostname' => optional($site->primaryDomain())->hostname,
            'url' => null,
            'error' => null,
            'checked_at' => now()->toIso8601String(),
            'checks' => [],
        ];
    }
}
