<?php

namespace App\Services\Servers\Bootstrap;

use App\Models\Server;

class KubernetesClusterBootstrapStrategy implements ServerBootstrapStrategy
{
    public function supports(Server $server): bool
    {
        return $server->isKubernetesCluster();
    }

    public function build(Server $server): array
    {
        return [];
    }

    public function buildArtifacts(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $config = is_array($meta['kubernetes'] ?? null) ? $meta['kubernetes'] : [];

        return [[
            'type' => 'cluster_summary',
            'key' => 'kubernetes-cluster',
            'label' => 'Kubernetes cluster',
            'content' => json_encode([
                'provider' => $server->provider?->value,
                'cluster_name' => $config['cluster_name'] ?? null,
                'namespace' => $config['namespace'] ?? 'default',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'metadata' => [
                'host_kind' => $server->hostKind(),
            ],
        ]];
    }
}
