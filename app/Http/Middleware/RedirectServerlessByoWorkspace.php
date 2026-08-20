<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Server;
use App\Models\Site;
use App\Support\Serverless\ServerlessWorkspaceUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep DigitalOcean Functions / serverless hosts off the BYO `/servers/{id}/…`
 * shape so the address bar and chrome match the Serverless product line.
 *
 * Function Sites still resolve under `/servers/…/sites/…` for Livewire mounts;
 * leftover namespace hosts (function deleted, host row still there) must not
 * render Servers overview chrome or bounce show ↔ overview on Lazy hydrate.
 */
final class RedirectServerlessByoWorkspace
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        if ($site instanceof Site) {
            $target = ServerlessWorkspaceUrl::legacyRedirectUrl($request, $site);
            if ($target !== null) {
                return $this->redirectUnlessCurrent($request, $target) ?? $next($request);
            }
        }

        $server = $request->route('server');
        if ($server instanceof Server && $this->isByoServerWorkspacePath($request, $server)) {
            $target = ServerlessWorkspaceUrl::forHost($server);
            if ($target !== null) {
                return $this->redirectUnlessCurrent($request, $target) ?? $next($request);
            }
        }

        return $next($request);
    }

    private function redirectUnlessCurrent(Request $request, string $target): ?Response
    {
        $current = $request->fullUrl();
        if ($target === $current || rtrim($target, '/') === rtrim($current, '/')) {
            return null;
        }

        return redirect()->to($target);
    }

    /**
     * True when this request is a BYO server workspace URL for $server
     * (`/servers/{id}` or `/servers/{id}/…`), not an unrelated `{server}` binding.
     */
    private function isByoServerWorkspacePath(Request $request, Server $server): bool
    {
        $path = $request->path();
        $keys = array_unique(array_filter([
            (string) $server->getKey(),
            (string) $server->getRouteKey(),
        ]));

        foreach ($keys as $key) {
            $prefix = 'servers/'.$key;
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
