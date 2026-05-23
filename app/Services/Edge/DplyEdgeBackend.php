<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use RuntimeException;

class DplyEdgeBackend implements EdgeBackend
{
    public function __construct(
        private readonly EdgeArtifactPublisher $publisher,
        private readonly EdgeHostMapPublisher $hostMapPublisher,
    ) {}

    public function providerKey(): string
    {
        return 'dply_edge';
    }

    public function publishDeployment(EdgeDeployment $deployment, Site $site, string $localArtifactDir): array
    {
        $this->publisher->uploadDirectory($localArtifactDir, $deployment->storage_prefix);
        $version = $this->hostMapPublisher->publish($site, $deployment);

        return [
            'live_url' => 'https://'.$site->edgeHostname(),
            'cf_kv_version' => $version,
        ];
    }

    public function unpublish(Site $site): void
    {
        foreach ($site->edgeDeployments as $deployment) {
            $this->publisher->deletePrefix($deployment->storage_prefix);
        }

        $this->hostMapPublisher->unpublish($site);
    }

    public function attachDomain(Site $site, string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            throw new RuntimeException('No active deployment to attach domain to.');
        }

        $deployment = EdgeDeployment::query()->findOrFail($activeId);
        $this->hostMapPublisher->publishHostname($site, $deployment, $hostname);

        $meta = $site->edgeMeta();
        $domains = is_array($meta['routing']['custom_domains'] ?? null) ? $meta['routing']['custom_domains'] : [];
        $domains[$hostname] = ['dns_status' => 'ready', 'attached_at' => now()->toIso8601String()];
        $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], [
            'edge' => array_merge($meta, [
                'routing' => array_merge(is_array($meta['routing'] ?? null) ? $meta['routing'] : [], [
                    'custom_domains' => $domains,
                ]),
            ]),
        ])]);

        return [
            [
                'name' => $hostname,
                'type' => 'CNAME',
                'value' => $site->edgeHostname(),
                'status' => 'pending',
            ],
        ];
    }

    public function detachDomain(Site $site, string $hostname): void
    {
        $this->hostMapPublisher->unpublishHostname($site, $hostname);

        $meta = $site->edgeMeta();
        $domains = is_array($meta['routing']['custom_domains'] ?? null) ? $meta['routing']['custom_domains'] : [];
        unset($domains[strtolower(trim($hostname))]);
        $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], [
            'edge' => array_merge($meta, [
                'routing' => array_merge(is_array($meta['routing'] ?? null) ? $meta['routing'] : [], [
                    'custom_domains' => $domains,
                ]),
            ]),
        ])]);
    }

    public function inspect(Site $site): array
    {
        $meta = $site->edgeMeta();
        $phase = match ($site->status) {
            Site::STATUS_EDGE_ACTIVE => 'ACTIVE',
            Site::STATUS_EDGE_PROVISIONING => 'BUILDING',
            Site::STATUS_EDGE_FAILED => 'FAILED',
            default => 'UNKNOWN',
        };

        return [
            'phase' => $phase,
            'live_url' => $site->edgeLiveUrl(),
            'active_deployment_id' => is_string($meta['active_deployment_id'] ?? null) ? $meta['active_deployment_id'] : null,
        ];
    }

    public function supportsSsr(): bool
    {
        return false;
    }
}
