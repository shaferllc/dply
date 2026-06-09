<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\ServerlessFunctionProxyController;
use App\Models\Site;
use App\Services\Serverless\ServerlessRoutingResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a request arrives on a hostname registered as a serverless
 * function's custom domain — and dply itself isn't the bare APP_URL host
 * — short-circuit before normal route resolution and dispatch the proxy
 * controller. Without this, hitting `api.acme.com/` would fall through to
 * the marketing welcome route (which has no host constraint).
 *
 * Custom-domain matches are cached per-host for 30s. The cache is
 * invalidated by {@see ServerlessRoutingResolver}
 * on every routing mutation so newly-added domains begin resolving within
 * one TTL window — usually instantly because the resolver clears the
 * map outright rather than per-host.
 */
class ResolveServerlessCustomDomain
{
    private const HOST_MAP_CACHE_KEY = 'serverless:custom-host-map';

    private const CACHE_TTL_SECONDS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '' || $host === $appHost) {
            return $next($request);
        }

        $map = $this->loadHostMap();
        $siteId = $map[$host] ?? null;
        if ($siteId === null) {
            return $next($request);
        }

        $site = Site::find($siteId);
        if ($site === null) {
            return $next($request);
        }

        return app(ServerlessFunctionProxyController::class)
            ->proxyForSite($request, $site, ltrim($request->path(), '/'));
    }

    /**
     * Drop the cached host→site_id map. Called by the resolver whenever
     * any site's routing meta changes, so an `addCustomDomain` is live on
     * the very next request.
     */
    public static function invalidateHostMap(): void
    {
        Cache::forget(self::HOST_MAP_CACHE_KEY);
    }

    /**
     * @return array<string, string>
     */
    private function loadHostMap(): array
    {
        return Cache::remember(self::HOST_MAP_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $map = [];

            // Scan sites whose meta JSON literally mentions custom_domains
            // — cheap text-match pre-filter portable across PG and SQLite,
            // exact membership is verified in PHP below. The cache keeps
            // the scan cost near zero in steady state.
            $sites = Site::query()
                ->where('meta', 'like', '%custom_domains%')
                ->get(['id', 'meta']);

            foreach ($sites as $site) {
                $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
                $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];
                $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

                foreach ($domains as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    if (($entry['dns_status'] ?? null) !== 'ready') {
                        continue;
                    }
                    $hostname = strtolower(trim((string) ($entry['hostname'] ?? '')));
                    if ($hostname !== '') {
                        $map[$hostname] = (string) $site->id;
                    }
                }
            }

            return $map;
        });
    }
}
