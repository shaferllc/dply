<?php

namespace App\Http\Middleware;

use App\Models\Server;
use App\Support\Servers\ServerInstalledServices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts with 404 when the matched workspace route is gated by a server-level
 * rule the bound {@see Server} doesn't satisfy:
 *
 *   - `requires_any_tags` in `config/server_workspace.nav` — the row needs at
 *     least one matching installed-service tag (existing behavior).
 *   - `role_nav_keys` in `config/server_workspace.php` — the server's
 *     server_role pins the sidebar to a focused subset (Redis/Valkey boxes);
 *     workspace routes outside that subset must not be reachable by deep link.
 *
 * Mirrors {@see server_workspace_nav_for_server()} so deep links can't surface
 * UI for sections we hide from the sidebar.
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

        $routeName = (string) $request->route()?->getName();
        if ($routeName === '') {
            return $next($request);
        }

        $navItem = $this->navItemForRoute($routeName);
        if ($navItem === null) {
            return $next($request);
        }

        if (! $this->passesRoleGate($server, $navItem)) {
            abort(404);
        }

        if (! $this->passesTagGate($server, $navItem)) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function navItemForRoute(string $routeName): ?array
    {
        foreach ((array) config('server_workspace.nav', []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['route'] ?? null) === $routeName || ($item['preview_route'] ?? null) === $routeName) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function passesRoleGate(Server $server, array $item): bool
    {
        $role = (string) ($server->meta['server_role'] ?? '');
        $allowedKeys = config('server_workspace.role_nav_keys.'.$role.'.keys');
        if (! is_array($allowedKeys) || $allowedKeys === []) {
            return true;
        }

        $key = $item['key'] ?? null;

        return is_string($key) && in_array($key, $allowedKeys, true);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function passesTagGate(Server $server, array $item): bool
    {
        $required = $item['requires_any_tags'] ?? null;
        if (! is_array($required) || $required === []) {
            return true;
        }

        $installed = ServerInstalledServices::tagsFor($server);
        if (array_key_exists('unknown', $installed)) {
            return true;
        }

        foreach ($required as $tag) {
            if (is_string($tag) && array_key_exists($tag, $installed)) {
                return true;
            }
        }

        return false;
    }
}
