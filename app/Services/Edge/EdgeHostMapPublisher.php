<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EdgeHostMapPublisher
{
    public function publish(Site $site, EdgeDeployment $deployment): int
    {
        $version = (int) $deployment->cf_kv_version + 1;
        $this->publishHostname($site, $deployment, $site->edgeHostname());

        foreach ($this->customHostnames($site) as $hostname) {
            $this->publishHostname($site, $deployment, $hostname);
        }

        return $version;
    }

    public function publishHostname(Site $site, EdgeDeployment $deployment, string $hostname): void
    {
        $payload = $this->routingPayload($deployment, $site);

        if (FakeEdgeProvision::enabled()) {
            $map = Cache::get('edge:fake:host-map', []);
            $map[strtolower($hostname)] = $payload;
            Cache::put('edge:fake:host-map', $map, now()->addDay());

            return;
        }

        $this->writeKv(strtolower($hostname), $payload);
    }

    public function unpublish(Site $site): void
    {
        $this->unpublishHostname($site, $site->edgeHostname());
        foreach ($this->customHostnames($site) as $hostname) {
            $this->unpublishHostname($site, $hostname);
        }
    }

    public function unpublishHostname(Site $site, string $hostname): void
    {
        $hostname = strtolower(trim($hostname));

        if (FakeEdgeProvision::enabled()) {
            $map = Cache::get('edge:fake:host-map', []);
            unset($map[$hostname]);
            Cache::put('edge:fake:host-map', $map, now()->addDay());

            return;
        }

        $accountId = (string) config('edge.cloudflare.account_id');
        $namespaceId = (string) config('edge.cloudflare.kv_namespace_id');
        $token = (string) config('edge.cloudflare.api_token');

        Http::withToken($token)
            ->delete("https://api.cloudflare.com/client/v4/accounts/{$accountId}/storage/kv/namespaces/{$namespaceId}/values/".rawurlencode($hostname))
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeKv(string $key, array $payload): void
    {
        $accountId = (string) config('edge.cloudflare.account_id');
        $namespaceId = (string) config('edge.cloudflare.kv_namespace_id');
        $token = (string) config('edge.cloudflare.api_token');

        Http::withToken($token)
            ->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')
            ->put("https://api.cloudflare.com/client/v4/accounts/{$accountId}/storage/kv/namespaces/{$namespaceId}/values/".rawurlencode($key))
            ->throw();
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

        return array_values(array_filter(array_map(
            fn ($host) => is_string($host) ? strtolower($host) : null,
            array_keys($domains),
        )));
    }
}
