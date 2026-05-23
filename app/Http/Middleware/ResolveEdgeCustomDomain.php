<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\EdgeStaticDevController;
use App\Models\Site;
use App\Support\Edge\FakeEdgeProvision;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * When fake-edge mode is on, serve Edge site hostnames from local artifacts.
 * Production Edge traffic is handled by the Cloudflare Worker.
 */
class ResolveEdgeCustomDomain
{
    private const HOST_MAP_CACHE_KEY = 'edge:custom-host-map';

    private const CACHE_TTL_SECONDS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        if (! FakeEdgeProvision::enabled()) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '' || $host === $appHost) {
            return $next($request);
        }

        $siteId = self::hostMap()[$host] ?? null;
        if ($siteId === null) {
            return $next($request);
        }

        $site = Site::find($siteId);
        if ($site === null || ! $site->usesEdgeRuntime()) {
            return $next($request);
        }

        return app(EdgeStaticDevController::class)->__invoke($request, ltrim($request->path(), '/'));
    }

    public static function invalidateHostMap(): void
    {
        Cache::forget(self::HOST_MAP_CACHE_KEY);
        Cache::forget('edge:fake:host-map');
    }

    /**
     * @return array<string, string>
     */
    public static function hostMap(): array
    {
        return Cache::remember(self::HOST_MAP_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $map = [];
            $sites = Site::query()
                ->whereNotNull('edge_backend')
                ->get(['id', 'slug', 'meta']);

            foreach ($sites as $site) {
                $hostname = $site->edgeHostname();
                if ($hostname !== '') {
                    $map[$hostname] = (string) $site->id;
                }

                $edge = is_array($site->meta['edge'] ?? null) ? $site->meta['edge'] : [];
                $routing = is_array($edge['routing'] ?? null) ? $edge['routing'] : [];
                $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
                foreach ($domains as $customHost => $info) {
                    if (is_string($customHost) && $customHost !== '' && ($info['dns_status'] ?? null) === 'ready') {
                        $map[strtolower($customHost)] = (string) $site->id;
                    }
                }
            }

            return $map;
        });
    }
}
