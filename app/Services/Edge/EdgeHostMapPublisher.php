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
        $this->publishHostname($site, $deployment, $site->edgeHostname(), $context, ! $site->isEdgePreview());

        foreach ($this->readyCustomHostnames($site) as $hostname) {
            $this->publishHostname($site, $deployment, $hostname, $context, true);
        }

        // Per-deploy stable aliases — generate (and persist) the first
        // time this deployment publishes, then re-emit on every
        // subsequent republish so the KV entries don't TTL out.
        $aliases = $deployment->aliasHostnames();
        if ($aliases === []) {
            $aliases = app(EdgeDeploymentAliasGenerator::class)->aliasesFor($site, $deployment);
            if ($aliases !== []) {
                $deployment->update(['aliases' => $aliases]);
            }
        }
        foreach ($aliases as $alias) {
            $this->publishHostname($site, $deployment, $alias, $context, false);
        }

        return $version;
    }

    public function publishHostname(
        Site $site,
        EdgeDeployment $deployment,
        string $hostname,
        ?EdgeDeliveryContext $context = null,
        bool $isProduction = false,
    ): void {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $payload = $this->routingPayload($deployment, $site, $isProduction);

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

        // Sweep alias hostnames from every deployment so torn-down sites
        // don't leave stale KV entries pointing at deleted R2 prefixes.
        foreach ($site->edgeDeployments as $deployment) {
            foreach ($deployment->aliasHostnames() as $alias) {
                $this->unpublishHostname($site, $alias, $context);
            }
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
    private function routingPayload(EdgeDeployment $deployment, Site $site, bool $isProduction = false): array
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
            'is_production' => $isProduction,
            'comment_widget_enabled' => $widgetEnabled,
        ];

        if (! $isProduction) {
            $accessGate = app(EdgeAccessGate::class)->kvPayloadForSite($site);
            if ($accessGate !== null) {
                $payload['access_gate'] = $accessGate;
            }
        }

        // Per-deploy config from dply.yaml — redirects/rewrites/headers
        // travel with the build so reverting a deploy reverts the
        // routing rules atomically. The Worker applies redirects first
        // (308/301/etc.), then rewrites (to internal paths), then layers
        // matching header rules onto the final response.
        $repoConfig = is_array($deployment->repo_config) ? $deployment->repo_config : null;
        if (is_array($repoConfig)) {
            $redirects = is_array($repoConfig['redirects'] ?? null) ? $repoConfig['redirects'] : [];
            $rewrites = is_array($repoConfig['rewrites'] ?? null) ? $repoConfig['rewrites'] : [];
            $headerRules = is_array($repoConfig['headers'] ?? null) ? $repoConfig['headers'] : [];

            if ($redirects !== []) {
                $payload['repo_redirects'] = array_values($redirects);
            }
            if ($rewrites !== []) {
                $payload['repo_rewrites'] = array_values($rewrites);
            }
            if ($headerRules !== []) {
                $payload['repo_header_rules'] = array_values($headerRules);
            }
        }

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

        // SSR: surface the per-deployment worker script name so the
        // platform Worker can dispatch to it instead of falling
        // through to R2/origin proxy logic. Script name lives on the
        // deployment meta (written by EdgeSsrBundleUploader).
        if (($edgeMeta['runtime_mode'] ?? 'static') === 'ssr') {
            $ssrMeta = is_array($deployment->meta['ssr'] ?? null) ? $deployment->meta['ssr'] : [];
            $scriptName = is_string($ssrMeta['script_name'] ?? null) ? trim($ssrMeta['script_name']) : '';
            if ($scriptName !== '') {
                $payload['runtime_mode'] = 'ssr';
                $payload['ssr_worker_script'] = $scriptName;
            }
        }

        // Middleware (P10a) — only for static + hybrid sites. SSR
        // sites bundle middleware via OpenNext so we'd double-run it.
        if (($edgeMeta['runtime_mode'] ?? 'static') !== 'ssr') {
            $mwMeta = is_array($deployment->meta['middleware'] ?? null) ? $deployment->meta['middleware'] : [];
            $mwScript = is_string($mwMeta['script_name'] ?? null) ? trim($mwMeta['script_name']) : '';
            if ($mwScript !== '') {
                $payload['middleware_worker_script'] = $mwScript;
            }
        }

        // Split traffic (P10d) — production-only, parent sites only.
        // The Worker reads `split` from KV, hashes the visitor cookie /
        // IP into a 0-99 bucket, and serves from preview_storage_prefix
        // for the configured percentage. Preview sites can't sub-split;
        // we always read split from the *parent's* meta.
        if (! $isPreview) {
            $split = is_array($edgeMeta['split'] ?? null) ? $edgeMeta['split'] : null;
            $percentage = is_array($split) ? (int) ($split['percentage'] ?? 0) : 0;
            $previewPrefix = is_array($split) ? (string) ($split['preview_storage_prefix'] ?? '') : '';
            if (is_array($split) && ($split['enabled'] ?? false) && $percentage > 0 && $previewPrefix !== '') {
                $payload['split'] = [
                    'preview_storage_prefix' => $previewPrefix,
                    'preview_deployment_id' => (string) ($split['preview_deployment_id'] ?? ''),
                    'percentage' => max(1, min(99, $percentage)),
                    'sticky_cookie' => is_string($split['sticky_cookie'] ?? null) ? $split['sticky_cookie'] : null,
                ];
            }
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
