<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Local/test backend — stores artifacts on disk and host map in cache.
 */
class FakeEdgeBackend implements EdgeBackend
{
    public function providerKey(): string
    {
        return 'dply_edge';
    }

    public function publishDeployment(EdgeDeployment $deployment, Site $site, string $localArtifactDir): array
    {
        $dest = $this->artifactRoot($deployment->storage_prefix);
        File::ensureDirectoryExists($dest);
        File::copyDirectory($localArtifactDir, $dest);

        $hostname = $site->edgeHostname();
        $routing = $this->routingPayload($deployment, $site);
        $map = $this->hostMap();
        $map[$hostname] = $routing;

        foreach ($this->customHostnames($site) as $customHost) {
            $map[$customHost] = $routing;
        }
        Cache::put($this->hostMapKey(), $map, now()->addDay());

        $liveUrl = 'https://'.$hostname;
        $version = (int) $deployment->cf_kv_version + 1;

        return [
            'live_url' => $liveUrl,
            'cf_kv_version' => $version,
        ];
    }

    public function unpublish(Site $site): void
    {
        $map = $this->hostMap();
        unset($map[$site->edgeHostname()]);
        foreach ($this->customHostnames($site) as $customHost) {
            unset($map[$customHost]);
        }
        Cache::put($this->hostMapKey(), $map, now()->addDay());

        $deployments = $site->edgeDeployments()->get();
        foreach ($deployments as $deployment) {
            File::deleteDirectory($this->artifactRoot($deployment->storage_prefix));
        }
    }

    public function attachDomain(Site $site, string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return [];
        }

        $deployment = EdgeDeployment::query()->find($activeId);
        if ($deployment === null) {
            return [];
        }

        $map = $this->hostMap();
        $map[$hostname] = $this->routingPayload($deployment, $site);
        Cache::put($this->hostMapKey(), $map, now()->addDay());

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

        return [];
    }

    public function detachDomain(Site $site, string $hostname): void
    {
        $hostname = strtolower(trim($hostname));
        $map = $this->hostMap();
        unset($map[$hostname]);
        Cache::put($this->hostMapKey(), $map, now()->addDay());

        $meta = $site->edgeMeta();
        $domains = is_array($meta['routing']['custom_domains'] ?? null) ? $meta['routing']['custom_domains'] : [];
        unset($domains[$hostname]);
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

    public function localFilePath(EdgeDeployment $deployment, string $path): ?string
    {
        $file = $this->artifactRoot($deployment->storage_prefix).'/'.ltrim($path, '/');
        if (! is_file($file)) {
            return null;
        }

        return $file;
    }

    public function resolveActiveDeployment(Site $site): ?EdgeDeployment
    {
        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return $site->edgeDeployments()->where('status', EdgeDeployment::STATUS_LIVE)->latest()->first();
        }

        return EdgeDeployment::query()->find($activeId);
    }

    private function artifactRoot(string $prefix): string
    {
        return rtrim(FakeEdgeProvision::storageRoot(), '/').'/'.trim($prefix, '/');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function hostMap(): array
    {
        return Cache::get($this->hostMapKey(), []);
    }

    private function hostMapKey(): string
    {
        return 'edge:fake:host-map';
    }

    /**
     * @return array<string, mixed>
     */
    private function routingPayload(EdgeDeployment $deployment, Site $site): array
    {
        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];

        return [
            'storage_prefix' => $deployment->storage_prefix,
            'deployment_id' => $deployment->id,
            'spa_fallback' => (bool) ($routing['spa_fallback'] ?? true),
            'headers' => is_array($routing['headers'] ?? null) ? $routing['headers'] : [],
        ];
    }

    /**
     * @return list<string>
     */
    private function customHostnames(Site $site): array
    {
        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $hosts = [];
        foreach ($domains as $hostname => $info) {
            if (is_string($hostname) && $hostname !== '') {
                $hosts[] = strtolower($hostname);
            }
        }

        return $hosts;
    }
}
