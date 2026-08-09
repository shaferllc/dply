<?php

declare(strict_types=1);

namespace App\Support\Serverless;

use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Product-line URLs for serverless function workspaces.
 *
 * Internal storage still uses Server+Site, but the user-facing IA is under
 * `/serverless/{site}/…` (Dashboard → Serverless → function), never
 * Dashboard → Servers → host → Sites → site.
 */
final class ServerlessWorkspaceUrl
{
    /**
     * Dedicated BYO leaf path suffixes that have matching `/serverless/{site}/…`
     * routes. Keys are the path after `servers/{server}/sites/{site}/`.
     *
     * @var array<string, string> byoSuffix => route name
     */
    public const LEAF_ROUTE_NAMES = [
        'deploying' => 'serverless.journey',
        'proxy-routing' => 'serverless.routing',
        'deployments' => 'serverless.deployments',
        'repository' => 'serverless.repository',
        'resources' => 'serverless.resources',
        'schedule' => 'serverless.schedule',
        'workers' => 'serverless.workers',
        'environment' => 'serverless.environment',
        'monitor' => 'serverless.monitor',
        'errors' => 'serverless.errors',
        'logs' => 'serverless.logs',
    ];

    /**
     * Map a `sites.*` / `serverless.journey` route name to the serverless product route.
     *
     * @var array<string, string>
     */
    public const SITES_ROUTE_TO_SERVERLESS = [
        'sites.show' => 'serverless.show',
        'sites.routing' => 'serverless.routing',
        'sites.deployments.index' => 'serverless.deployments',
        'sites.repository' => 'serverless.repository',
        'sites.resources' => 'serverless.resources',
        'sites.schedule' => 'serverless.schedule',
        'sites.workers' => 'serverless.workers',
        'sites.environment' => 'serverless.environment',
        'sites.monitor' => 'serverless.monitor',
        'sites.errors' => 'serverless.errors',
        'sites.logs' => 'serverless.logs',
        'serverless.journey' => 'serverless.journey',
    ];

    /**
     * @param  array<string, mixed>  $query
     */
    public static function show(Site $site, string $section = 'general', array $query = []): string
    {
        $params = ['site' => $site];
        if ($section !== '' && $section !== 'general') {
            $params['section'] = $section;
        }

        return route('serverless.show', $params + $query);
    }

    public static function journey(Site $site): string
    {
        return route('serverless.journey', ['site' => $site]);
    }

    /**
     * Resolve a sidebar / deep-link `sites.*` route to the serverless product URL
     * when the site is a function; otherwise return null (caller keeps sites.*).
     *
     * @param  array<string, mixed>  $extra  section, tab, query, etc.
     */
    public static function forSitesRoute(string $routeName, Site $site, array $extra = []): ?string
    {
        if (! $site->usesFunctionsRuntime()) {
            return null;
        }

        $mapped = self::SITES_ROUTE_TO_SERVERLESS[$routeName] ?? null;
        if ($mapped === null) {
            return null;
        }

        if ($mapped === 'serverless.show') {
            $section = (string) ($extra['section'] ?? 'general');
            unset($extra['section'], $extra['server'], $extra['site']);

            return self::show($site, $section, $extra);
        }

        unset($extra['server'], $extra['site'], $extra['section']);

        return route($mapped, ['site' => $site] + $extra);
    }

    /**
     * When a functions site is opened on a legacy BYO `/servers/…/sites/…` URL,
     * return the product-line target path (absolute URL) or null if unknown.
     */
    public static function legacyRedirectUrl(Request $request, Site $site): ?string
    {
        if (! $site->usesFunctionsRuntime()) {
            return null;
        }

        if ($request->routeIs('serverless.*')) {
            return null;
        }

        $path = $request->path();
        $prefix = 'servers/'.$site->server_id.'/sites/'.$site->id;
        if ($path !== $prefix && ! str_starts_with($path, $prefix.'/')) {
            // Also accept route-model bound ids that stringify differently
            $server = $site->server;
            if ($server !== null) {
                $prefix = 'servers/'.$server->getRouteKey().'/sites/'.$site->getRouteKey();
            }
            if ($path !== $prefix && ! str_starts_with($path, $prefix.'/')) {
                return null;
            }
        }

        $suffix = trim(substr($path, strlen($prefix)), '/');
        $query = $request->query();

        if ($suffix === '' || $suffix === 'general') {
            // Mirror SiteWorkspaceController: never-live / failed first deploy
            // stays on the journey rather than an empty workspace.
            if (
                in_array($site->status, [
                    Site::STATUS_FUNCTIONS_CONFIGURED,
                    Site::STATUS_FUNCTIONS_FAILED,
                ], true)
                && $site->last_deploy_at === null
            ) {
                return self::journey($site);
            }

            return self::show($site, 'general', $query);
        }

        // Legacy serverless bookmark used `/edge-routing` before `/proxy-routing`.
        if ($suffix === 'edge-routing') {
            return route('serverless.routing', ['site' => $site] + $query);
        }

        // deployments/{id}
        if (preg_match('#^deployments/([^/]+)$#', $suffix, $m) === 1) {
            return route('serverless.deployments.show', ['site' => $site, 'deployment' => $m[1]] + $query);
        }

        $first = explode('/', $suffix, 2)[0];
        if (isset(self::LEAF_ROUTE_NAMES[$first]) && ! str_contains($suffix, '/')) {
            return route(self::LEAF_ROUTE_NAMES[$first], ['site' => $site] + $query);
        }

        // Settings sections (general, platform, runtime, …)
        if (preg_match('#^[a-z0-9-]+$#', $suffix) === 1) {
            return self::show($site, $suffix, $query);
        }

        return null;
    }
}
