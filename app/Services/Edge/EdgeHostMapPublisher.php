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

        foreach ($this->readyCustomHostnames($site) as $hostname) {
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
        foreach ($this->readyCustomHostnames($site) as $hostname) {
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
        $edgeMeta = $site->edgeMeta();
        $routing = is_array($edgeMeta['routing'] ?? null) ? $edgeMeta['routing'] : [];
        $isPreview = ! empty($edgeMeta['preview_parent_site_id']);
        $widgetMeta = is_array($edgeMeta['comment_widget'] ?? null) ? $edgeMeta['comment_widget'] : [];
        $widgetEnabled = $isPreview && (bool) ($widgetMeta['enabled'] ?? false);
        // Inherit widget config from the parent site so a single toggle
        // on the parent applies to every PR preview spawned from it.
        if ($isPreview && ! $widgetEnabled) {
            $parentId = $edgeMeta['preview_parent_site_id'] ?? null;
            if (is_string($parentId)) {
                $parent = Site::query()->find($parentId);
                $parentMeta = $parent?->edgeMeta() ?? [];
                $parentWidget = is_array($parentMeta['comment_widget'] ?? null) ? $parentMeta['comment_widget'] : [];
                if ((bool) ($parentWidget['enabled'] ?? false)) {
                    $widgetEnabled = true;
                    $widgetMeta = array_merge($parentWidget, $widgetMeta);
                }
            }
        }

        $payload = [
            'storage_prefix' => $deployment->storage_prefix,
            'deployment_id' => $deployment->id,
            'site_id' => (string) $site->id,
            'organization_id' => (string) $site->organization_id,
            'spa_fallback' => (bool) ($routing['spa_fallback'] ?? true),
            'headers' => is_array($routing['headers'] ?? null) ? $routing['headers'] : [],
            'is_preview' => $isPreview,
            'comment_widget_enabled' => $widgetEnabled,
        ];

        if ($widgetEnabled) {
            $token = is_string($widgetMeta['token'] ?? null) ? trim((string) $widgetMeta['token']) : '';
            if ($token !== '') {
                $payload['comment_widget_token'] = $token;
            }
            $apiBase = rtrim((string) config('app.url'), '/');
            if ($apiBase !== '') {
                $payload['comment_widget_api_base'] = $apiBase;
            }
        }

        // Image optimization is independent of runtime mode — applies
        // to both static and hybrid sites. The Worker only enables the
        // /_dply/image route when a signing secret is present.
        $images = is_array($edgeMeta['images'] ?? null) ? $edgeMeta['images'] : [];
        $imageSecret = is_string($images['signing_secret'] ?? null) ? trim((string) $images['signing_secret']) : '';
        if ($imageSecret !== '') {
            $payload['image_signing_secret'] = $imageSecret;
            $allowed = is_array($images['allowed_hosts'] ?? null) ? $images['allowed_hosts'] : [];
            $payload['image_allowed_hosts'] = array_values(array_filter(array_map(
                fn ($host) => is_string($host) && $host !== '' ? strtolower($host) : null,
                $allowed,
            )));
        }

        if (($edgeMeta['runtime_mode'] ?? 'static') === 'hybrid') {
            $origin = is_array($edgeMeta['origin'] ?? null) ? $edgeMeta['origin'] : [];
            $originUrl = trim((string) ($origin['url'] ?? ''));
            if ($originUrl !== '') {
                $payload['origin_url'] = $originUrl;
                $routes = is_array($origin['routes'] ?? null) ? $origin['routes'] : [];
                $payload['origin_routes'] = array_values(array_filter(array_map(
                    fn ($route) => is_string($route) ? $route : null,
                    $routes,
                )));
                $authSecret = is_string($origin['auth_secret'] ?? null) ? trim((string) $origin['auth_secret']) : '';
                if ($authSecret !== '') {
                    $payload['origin_auth_secret'] = $authSecret;
                }
                $failover = is_string($origin['failover_html'] ?? null) ? (string) $origin['failover_html'] : '';
                if ($failover !== '') {
                    $payload['origin_failover_html'] = $failover;
                }
            }
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function readyCustomHostnames(Site $site): array
    {
        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $hosts = [];
        foreach ($domains as $hostname => $info) {
            if (! is_string($hostname) || $hostname === '') {
                continue;
            }
            if (is_array($info) && ($info['dns_status'] ?? null) !== 'ready') {
                continue;
            }
            $hosts[] = strtolower($hostname);
        }

        return $hosts;
    }
}
