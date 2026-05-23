<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Support\Edge\EdgeDeliveryContext;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EdgeHostMapPublisher
{
    public function publish(Site $site, EdgeDeployment $deployment, ?EdgeDeliveryContext $context = null): int
    {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $version = (int) $deployment->cf_kv_version + 1;
        $this->publishHostname($site, $deployment, $site->edgeHostname(), $context);

        foreach ($this->customHostnames($site) as $hostname) {
            $this->publishHostname($site, $deployment, $hostname, $context);
        }

        return $version;
    }

    public function publishHostname(
        Site $site,
        EdgeDeployment $deployment,
        string $hostname,
        ?EdgeDeliveryContext $context = null,
    ): void {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $payload = $this->routingPayload($deployment, $site);

        if (FakeEdgeProvision::enabled()) {
            $map = Cache::get('edge:fake:host-map', []);
            $map[strtolower($hostname)] = $payload;
            Cache::put('edge:fake:host-map', $map, now()->addDay());

            return;
        }

        $this->writeKv(strtolower($hostname), $payload, $context);
    }

    public function unpublish(Site $site, ?EdgeDeliveryContext $context = null): void
    {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $this->unpublishHostname($site, $site->edgeHostname(), $context);
        foreach ($this->customHostnames($site) as $hostname) {
            $this->unpublishHostname($site, $hostname, $context);
        }
    }

    public function unpublishHostname(Site $site, string $hostname, ?EdgeDeliveryContext $context = null): void
    {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $hostname = strtolower(trim($hostname));

        if (FakeEdgeProvision::enabled()) {
            $map = Cache::get('edge:fake:host-map', []);
            unset($map[$hostname]);
            Cache::put('edge:fake:host-map', $map, now()->addDay());

            return;
        }

        Http::withToken($context->apiToken)
            ->delete($this->kvValueUrl($context, $hostname))
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeKv(string $key, array $payload, EdgeDeliveryContext $context): void
    {
        Http::withToken($context->apiToken)
            ->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')
            ->put($this->kvValueUrl($context, $key))
            ->throw();
    }

    private function kvValueUrl(EdgeDeliveryContext $context, string $key): string
    {
        return 'https://api.cloudflare.com/client/v4/accounts/'.$context->accountId
            .'/storage/kv/namespaces/'.$context->kvNamespaceId
            .'/values/'.rawurlencode($key);
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
