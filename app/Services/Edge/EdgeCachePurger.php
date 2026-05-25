<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\Site;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Purge entries from the Worker EDGE_CACHE KV namespace.
 *
 * The Worker stores cached origin responses keyed by
 * `edge_cache:{site_id}:{path}` and a per-tag pointer at
 * `edge_cache_tag:{site_id}:{tag}` that references the most recently
 * cached key for that tag. {@see purgeByTag()} reads the pointer,
 * deletes the referenced cache entry, then deletes the pointer itself.
 *
 * Per-site purge wipes the cache pointers for *known* tags only — we
 * don't list/scan all keys because KV `list` is rate-limited and
 * iteration is expensive on busy namespaces. For full per-site wipe
 * the operator should redeploy with a different storage prefix, which
 * causes natural cache misses on the new prefix.
 */
class EdgeCachePurger
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    /**
     * @return array{ok: bool, purged_keys: list<string>, message: string}
     */
    public function purgeByTag(Site $site, string $tag): array
    {
        $tag = trim($tag);
        if ($tag === '') {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'Empty tag.'];
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $tag) !== 1 || strlen($tag) > 128) {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'Invalid tag — letters, digits, dot, dash, underscore only.'];
        }

        $context = $this->contextResolver->forSite($site);
        if ($context->cacheKvNamespaceId === '') {
            return ['ok' => false, 'purged_keys' => [], 'message' => 'Edge cache namespace not configured for this site.'];
        }

        $siteId = (string) $site->id;
        $tagKey = "edge_cache_tag:{$siteId}:{$tag}";

        // 1) Read the tag pointer to find the cache key it references.
        $tagResp = $this->http
            ->withToken($context->apiToken)
            ->timeout(10)
            ->get($this->kvValueUrl($context->accountId, $context->cacheKvNamespaceId, $tagKey));

        if ($tagResp->status() === 404 || trim($tagResp->body()) === '') {
            return ['ok' => true, 'purged_keys' => [], 'message' => 'No cache entries tagged with that value.'];
        }
        if (! $tagResp->successful()) {
            Log::warning('EdgeCachePurger: tag pointer fetch failed', ['site' => $siteId, 'tag' => $tag, 'status' => $tagResp->status()]);

            return ['ok' => false, 'purged_keys' => [], 'message' => "Cloudflare KV read failed (HTTP {$tagResp->status()})."];
        }

        $cacheKey = trim($tagResp->body());

        // 2) Delete the cached response entry, then the tag pointer.
        $purged = [];
        foreach ([$cacheKey, $tagKey] as $key) {
            $del = $this->http
                ->withToken($context->apiToken)
                ->timeout(10)
                ->delete($this->kvValueUrl($context->accountId, $context->cacheKvNamespaceId, $key));
            if ($del->successful() || $del->status() === 404) {
                $purged[] = $key;
            } else {
                Log::warning('EdgeCachePurger: delete failed', ['site' => $siteId, 'key' => $key, 'status' => $del->status()]);
            }
        }

        return [
            'ok' => true,
            'purged_keys' => $purged,
            'message' => sprintf('Purged %d entries for tag "%s".', count($purged), $tag),
        ];
    }

    private function kvValueUrl(string $accountId, string $namespaceId, string $key): string
    {
        return self::BASE
            .'/accounts/'.$accountId
            .'/storage/kv/namespaces/'.$namespaceId
            .'/values/'.rawurlencode($key);
    }
}
