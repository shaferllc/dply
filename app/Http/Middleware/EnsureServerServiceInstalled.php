<?php

namespace App\Http\Middleware;

use App\Models\Server;
use App\Support\Servers\ServerInstalledServices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts with 404 when the matched route is gated by `requires_any_tags` in
 * `config/server_workspace.nav` and the bound {@see Server} has none of the listed
 * service tags. Mirrors {@see server_workspace_nav_for_server()} so deep links can't
 * surface UI for services we hide from the sidebar.
 *
 * Fails open when the provision stack summary is unknown (matches the helper).
 */
class EnsureServerServiceInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $server = $request->route('server');
        if (! $server instanceof Server) {
            return $next($request);
        }

        $required = $this->requiredTagsForRoute((string) $request->route()?->getName());
        if ($required === []) {
            return $next($request);
        }

        $installed = ServerInstalledServices::tagsFor($server);
        if (array_key_exists('unknown', $installed)) {
            return $next($request);
        }

        foreach ($required as $tag) {
            if (array_key_exists($tag, $installed)) {
                return $next($request);
            }
        }

        abort(404);
    }

    /**
     * @return list<string>
     */
    private function requiredTagsForRoute(string $routeName): array
    {
        if ($routeName === '') {
            return [];
        }
        foreach ((array) config('server_workspace.nav', []) as $item) {
            if (! is_array($item) || ($item['route'] ?? null) !== $routeName) {
                continue;
            }
            $required = $item['requires_any_tags'] ?? null;
            if (! is_array($required)) {
                return [];
            }

            return array_values(array_filter($required, 'is_string'));
        }

        return [];
    }
}
