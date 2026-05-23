<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use RuntimeException;

/**
 * Shared Cloudflare publish/unpublish/domain logic for platform and BYO backends.
 */
class CloudflareEdgeDelivery
{
    public function __construct(
        private readonly EdgeArtifactPublisher $artifactPublisher,
        private readonly EdgeHostMapPublisher $hostMapPublisher,
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    /**
     * @return array{live_url: ?string, cf_kv_version: int}
     */
    public function publishDeployment(EdgeDeployment $deployment, Site $site, string $localArtifactDir): array
    {
        $context = $this->contextResolver->forSite($site);
        $uploaded = $this->artifactPublisher->uploadDirectory(
            $localArtifactDir,
            $deployment->storage_prefix,
            $context->diskName,
        );
        if ($uploaded < 1) {
            throw new RuntimeException('Refusing to publish: no artifacts uploaded to R2.');
        }
        $version = $this->hostMapPublisher->publish($site, $deployment, $context);

        return [
            'live_url' => 'https://'.$site->edgeHostname(),
            'cf_kv_version' => $version,
        ];
    }

    public function unpublish(Site $site): void
    {
        $context = $this->contextResolver->forSite($site);

        foreach ($site->edgeDeployments as $deployment) {
            $this->artifactPublisher->deletePrefix($deployment->storage_prefix, $context->diskName);
        }

        $this->hostMapPublisher->unpublish($site, $context);
    }

    /**
     * @return list<array{name: string, type: string, value: string, status: string}>
     */
    public function attachDomain(Site $site, string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            throw new RuntimeException('No active deployment to attach domain to.');
        }

        $deployment = EdgeDeployment::query()->findOrFail($activeId);
        $context = $this->contextResolver->forSite($site);
        $this->hostMapPublisher->publishHostname($site, $deployment, $hostname, $context);

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
        $context = $this->contextResolver->forSite($site);
        $this->hostMapPublisher->unpublishHostname($site, $hostname, $context);

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

    /**
     * @return array{phase: string, live_url: ?string, active_deployment_id: ?string}
     */
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
}
