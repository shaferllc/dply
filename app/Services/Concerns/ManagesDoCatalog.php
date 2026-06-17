<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoCatalog
{


    /**
     * Get available regions.
     *
     * @return array<int, array<string, mixed>>
     */
    /** @return array<string, mixed> */
    public function getRegions(): array
    {
        return $this->cachedCatalogList('do_regions', '/regions', 'regions');
    }

    /**
     * Get available sizes (plans).
     *
     * @return array<int, array<string, mixed>>
     */
    /** @return array<string, mixed> */
    public function getSizes(): array
    {
        return $this->cachedCatalogList('do_sizes', '/sizes', 'sizes');
    }

    /**
     * Cache regions/sizes responses per token. The wizard renders these on every
     * step and they don't change often — a 10 minute cache keeps the page fast
     * even when the DO API is slow, and bounded HTTP timeouts (in request())
     * keep the worst-case render under ~10s instead of stalling for 30s+.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cachedCatalogList(string $kind, string $path, string $primaryKey): array
    {
        $cacheKey = $kind.':'.sha1($this->token);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('get', $path);
        $this->assertSuccess($response, 'list '.$primaryKey);
        $data = $response->json();
        $items = $data[$primaryKey] ?? $data['data'] ?? [];
        $items = is_array($items) ? $items : [];

        Cache::put($cacheKey, $items, now()->addMinutes(10));

        return $items;
    }

    /**
     * List VPCs in the account, optionally filtered by region.
     *
     * @return array<int, array{id: string, name: string, region: string, ip_range: string}>
     */
    /** @return array<string, mixed> */
    public function listVpcs(?string $region = null): array
    {
        $response = $this->request('get', '/vpcs');
        $this->assertSuccess($response, 'list vpcs');
        $data = $response->json();
        $vpcs = $data['vpcs'] ?? [];
        if (! is_array($vpcs)) {
            return [];
        }

        $out = [];
        foreach ($vpcs as $v) {
            if ($region !== null && ($v['region'] ?? '') !== $region) {
                continue;
            }
            $out[] = [
                'id' => (string) ($v['id'] ?? ''),
                'name' => (string) ($v['name'] ?? ''),
                'region' => (string) ($v['region'] ?? ''),
                'ip_range' => (string) ($v['ip_range'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Validate token with a lightweight account endpoint.
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/account');
        $this->assertSuccess($response, 'validate token');
    }

    /**
     * Get available images (distributions, snapshots).
     *
     * @return array<int, array<string, mixed>>
     */
    /** @return array<string, mixed> */
    public function getImages(): array
    {
        $response = $this->request('get', '/images');
        $this->assertSuccess($response, 'list images');
        $data = $response->json();
        $images = $data['images'] ?? $data['data'] ?? [];

        return is_array($images) ? $images : [];
    }
}
