<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Support\Providers\ProviderCatalogCache;
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
    public function getRegions(): array
    {
        return $this->cachedCatalogList('regions', '/regions', 'regions');
    }

    /**
     * Get available sizes (plans).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSizes(): array
    {
        return $this->cachedCatalogList('sizes', '/sizes', 'sizes');
    }

    /**
     * Cache regions/sizes (and other catalog lists) for hours. The wizard
     * renders these on every step; a shared store keeps the page off the
     * DigitalOcean API after the first success. Timeouts trip the provider
     * circuit instead of caching an empty list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cachedCatalogList(string $kind, string $path, string $primaryKey): array
    {
        $scope = ProviderCatalogCache::scopeForToken($this->token);

        return ProviderCatalogCache::remember('digitalocean', $kind, $scope, function () use ($path, $primaryKey): array {
            // DO paginates these lists (default per_page=20), and /sizes alone
            // has 100+ plans — a single unpaginated GET silently dropped every
            // size beyond the 20 cheapest. Request the max page size and follow
            // pages until the list is exhausted.
            $items = [];
            $page = 1;
            do {
                $response = $this->request('get', $path, ['per_page' => 200, 'page' => $page]);
                $this->assertSuccess($response, 'list '.$primaryKey);
                $data = $response->json();
                $batch = $data[$primaryKey] ?? $data['data'] ?? [];
                $batch = is_array($batch) ? $batch : [];
                $items = array_merge($items, $batch);

                $hasNext = filled($data['links']['pages']['next'] ?? null);
                $page++;
            } while ($hasNext && $batch !== [] && $page <= 10);

            return $items;
        });
    }

    /**
     * Drop the cached regions/sizes lists for this token — used by callers
     * that miss a lookup (e.g. a just-resized plan) and need a fresh fetch
     * before concluding the item doesn't exist.
     */
    public function forgetCatalogCaches(): void
    {
        $scope = ProviderCatalogCache::scopeForToken($this->token);
        ProviderCatalogCache::forget('digitalocean', 'regions', $scope);
        ProviderCatalogCache::forget('digitalocean', 'sizes', $scope);
        ProviderCatalogCache::forget('digitalocean', 'images', $scope);
        ProviderCatalogCache::forget('digitalocean', 'vpcs', $scope);
        Cache::forget('do_regions:'.sha1($this->token));
        Cache::forget('do_sizes:'.sha1($this->token));
    }

    /**
     * List VPCs in the account, optionally filtered by region.
     *
     * @return array<int, array{id: string, name: string, region: string, ip_range: string}>
     */
    public function listVpcs(?string $region = null): array
    {
        $vpcs = ProviderCatalogCache::remember(
            'digitalocean',
            'vpcs',
            ProviderCatalogCache::scopeForToken($this->token),
            function (): array {
                $response = $this->request('get', '/vpcs');
                $this->assertSuccess($response, 'list vpcs');
                $data = $response->json();
                $raw = $data['vpcs'] ?? [];

                return is_array($raw) ? $raw : [];
            },
        );
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
    public function getImages(): array
    {
        return ProviderCatalogCache::remember(
            'digitalocean',
            'images',
            ProviderCatalogCache::scopeForToken($this->token),
            function (): array {
                $response = $this->request('get', '/images');
                $this->assertSuccess($response, 'list images');
                $data = $response->json();
                $images = $data['images'] ?? $data['data'] ?? [];

                return is_array($images) ? $images : [];
            },
        );
    }
}
