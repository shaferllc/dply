<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Services\Deploy\LocalPublishedPortAllocator;

class ContainerPublicationManager
{
    public function __construct(
        private readonly LocalPublishedPortAllocator $portAllocator,
        private readonly TestingHostnameProvisioner $testingHostnameProvisioner,
    ) {}

    public function provision(Site $site): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $runtimeTarget = is_array($meta['runtime_target'] ?? null) ? $meta['runtime_target'] : [];
        $publication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];

        if ($site->usesLocalDockerHostRuntime()) {
            $port = (int) ($publication['port'] ?? 0);
            if ($port <= 0) {
                $port = $this->portAllocator->reserve($site, $site->usesDockerRuntime() ? 8080 : 8090);
            }

            $hostname = optional($site->primaryDomain())->hostname ?: strtolower($site->slug).'.local.dply.test';

            $runtimeTarget['publication'] = array_merge($publication, [
                'kind' => 'local_preview',
                'hostname' => $hostname,
                'url' => 'http://127.0.0.1:'.$port,
                'port' => $port,
                'dns_provider' => 'local',
                'status' => 'pending',
                'published_at' => now()->toIso8601String(),
            ]);
            $runtimeTarget['status'] = $runtimeTarget['status'] ?? 'pending';
        } else {
            $preview = $this->testingHostnameProvisioner->provision($site);
            $preview = $preview?->fresh();

            $runtimeTarget['publication'] = array_merge($publication, [
                'kind' => 'managed_preview',
                'hostname' => $preview?->hostname,
                'url' => $preview?->hostname ? 'http://'.$preview->hostname : null,
                'dns_provider' => $preview?->provider_type,
                'status' => $preview?->dns_status ?? 'pending',
                'published_at' => now()->toIso8601String(),
            ]);
        }

        $meta['runtime_target'] = $runtimeTarget;
        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * @return array{ok: bool, hostname: ?string, url: ?string, error: ?string, checked_at: string, checks: array<int, array<string, mixed>>}
     */
    public function readyResult(Site $site): array
    {
        $runtimeTarget = $site->runtimeTarget();
        $publication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $hostname = is_string($publication['hostname'] ?? null) ? $publication['hostname'] : optional($site->primaryDomain())->hostname;
        $url = is_string($publication['url'] ?? null) ? $publication['url'] : null;

        if ($site->usesLocalDockerHostRuntime()) {
            $ok = $this->localEndpointReady($url);
            $this->storePublicationStatus($site, $ok ? 'ready' : 'pending');

            return [
                'ok' => $ok,
                'hostname' => $hostname,
                'url' => $url,
                'error' => $ok ? null : 'Local runtime endpoint has not responded yet.',
                'checked_at' => now()->toIso8601String(),
                'checks' => [],
            ];
        }

        $preview = $site->primaryPreviewDomain();
        $ok = $preview instanceof SitePreviewDomain && $preview->dns_status === 'ready';
        $this->storePublicationStatus($site, $ok ? 'ready' : 'pending');

        return [
            'ok' => true,
            'hostname' => $hostname ?? $preview?->hostname,
            'url' => $url ?? ($preview?->hostname ? 'http://'.$preview->hostname : null),
            'error' => null,
            'checked_at' => now()->toIso8601String(),
            'checks' => [],
        ];
    }

    private function localEndpointReady(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 3,
                    'ignore_errors' => true,
                ],
            ]);

            $contents = @file_get_contents($url, false, $context);

            return $contents !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function storePublicationStatus(Site $site, string $status): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $runtimeTarget = is_array($meta['runtime_target'] ?? null) ? $meta['runtime_target'] : [];
        $publication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];

        $runtimeTarget['publication'] = array_merge($publication, [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
        ]);

        $meta['runtime_target'] = $runtimeTarget;
        $site->forceFill(['meta' => $meta])->save();
    }
}
